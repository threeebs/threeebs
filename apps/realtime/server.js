import http from 'node:http';
import crypto from 'node:crypto';
import Redis from 'ioredis';
import { WebSocketServer } from 'ws';
import * as Y from 'yjs';
import * as syncProtocol from 'y-protocols/sync';
import * as awarenessProtocol from 'y-protocols/awareness';
import * as encoding from 'lib0/encoding';
import * as decoding from 'lib0/decoding';

const PORT = numberEnv('REALTIME_PORT', 1234, 1, 65535);
const TICKET_PREFIX = 'threeebs:realtime:ticket:';
const INTERNAL_URL = requiredEnv('REALTIME_INTERNAL_URL').replace(/\/$/, '');
const INTERNAL_SECRET = requiredEnv('REALTIME_INTERNAL_SECRET');
const CHECKPOINT_DEBOUNCE_MS = numberEnv('REALTIME_CHECKPOINT_DEBOUNCE_MS', 2000, 250, 60000);
const CHECKPOINT_MAX_MS = numberEnv('REALTIME_CHECKPOINT_MAX_MS', 10000, 1000, 120000);
const MAX_MESSAGE_BYTES = numberEnv('REALTIME_MAX_MESSAGE_BYTES', 1048576, 1024, 16777216);
const MESSAGE_SYNC = 0;
const MESSAGE_AWARENESS = 1;
const INITIAL = Symbol('initial');
const rooms = new Map();
const loadingRooms = new Map();

const redis = new Redis({
    host: process.env.REDIS_HOST || 'redis',
    port: numberEnv('REDIS_PORT', 6379, 1, 65535),
    password: requiredEnv('REDIS_PASSWORD'),
    maxRetriesPerRequest: 2,
    enableReadyCheck: true
});

function requiredEnv(name) {
    const value = process.env[name];
    if (!value) throw new Error(`Missing required environment variable: ${name}`);
    return value;
}

function numberEnv(name, fallback, minimum, maximum) {
    const value = Number(process.env[name] || fallback);
    if (!Number.isInteger(value) || value < minimum || value > maximum) {
        throw new Error(`Invalid ${name}`);
    }
    return value;
}

function jsonResponse(response, status, payload) {
    const body = JSON.stringify(payload);
    response.writeHead(status, {
        'content-type': 'application/json; charset=utf-8',
        'content-length': Buffer.byteLength(body),
        'cache-control': 'no-store'
    });
    response.end(body);
}

function socketPath(request) {
    const url = new URL(request.url || '/', 'http://realtime');
    const match = url.pathname.match(/^\/realtime\/([a-f0-9]{64})$/);
    return match ? { url, room: match[1] } : null;
}

async function consumeTicket(token, room) {
    if (!/^[a-f0-9]{64}$/.test(token)) return null;
    const raw = await redis.call('GETDEL', TICKET_PREFIX + token);
    if (!raw) return null;
    let ticket;
    try {
        ticket = JSON.parse(raw);
    } catch {
        return null;
    }
    if (
        ticket.room_id !== room ||
        ticket.expires_at * 1000 < Date.now() ||
        ticket.environment_type !== 'sandbox' ||
        !ticket.capabilities?.includes('write') ||
        !/^[a-f0-9]{64}$/.test(ticket.control_token || '')
    ) return null;
    return ticket;
}

async function internalRequest(path, options = {}) {
    const response = await fetch(INTERNAL_URL + path, {
        ...options,
        headers: {
            'content-type': 'application/json',
            'x-threeebs-realtime-secret': INTERNAL_SECRET,
            ...(options.headers || {})
        },
        signal: AbortSignal.timeout(10000)
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(payload.error || `Internal API returned ${response.status}`);
        error.status = response.status;
        throw error;
    }
    return payload;
}

function sameRoom(left, right) {
    return left.project_uuid === right.project_uuid &&
        left.environment_uuid === right.environment_uuid &&
        left.path === right.path;
}

async function roomFor(ticket) {
    const existing = rooms.get(ticket.room_id);
    if (existing) {
        if (!sameRoom(existing.ticket, ticket)) throw new Error('Room identity mismatch');
        return existing;
    }
    if (loadingRooms.has(ticket.room_id)) {
        const loading = await loadingRooms.get(ticket.room_id);
        if (!sameRoom(loading.ticket, ticket)) throw new Error('Room identity mismatch');
        return loading;
    }

    const loading = createRoom(ticket);
    loadingRooms.set(ticket.room_id, loading);
    try {
        return await loading;
    } finally {
        loadingRooms.delete(ticket.room_id);
    }
}

async function createRoom(ticket) {
    const params = new URLSearchParams({
        user_uuid: ticket.user_uuid,
        project_uuid: ticket.project_uuid,
        environment_uuid: ticket.environment_uuid,
        path: ticket.path,
        room: ticket.room_id
    });
    const initial = await internalRequest('/api/realtime/file?' + params);
    const room = {
        ticket,
        doc: new Y.Doc(),
        awareness: null,
        clients: new Map(),
        hash: initial.file.hash,
        dirty: false,
        revision: 0,
        checkpointTimer: null,
        maxTimer: null,
        checkpointRunning: false,
        checkpointTicket: ticket
    };
    room.awareness = new awarenessProtocol.Awareness(room.doc);
    room.awareness.setLocalState(null);
    room.doc.transact(() => room.doc.getText('monaco').insert(0, initial.file.content), INITIAL);

    room.doc.on('update', (update, origin) => {
        const client = room.clients.get(origin);
        if (client) room.checkpointTicket = client.ticket;
        broadcast(room, syncMessage(update), origin);
        if (origin !== INITIAL) {
            room.revision += 1;
            scheduleCheckpoint(room);
        }
    });
    room.awareness.on('update', ({ added, updated, removed }, origin) => {
        const changed = added.concat(updated, removed);
        if (origin && room.clients.has(origin)) {
            const controlled = room.clients.get(origin).awarenessIds;
            added.concat(updated).forEach(id => controlled.add(id));
            removed.forEach(id => controlled.delete(id));
        }
        broadcast(room, awarenessMessage(room.awareness, changed), origin);
    });
    rooms.set(ticket.room_id, room);
    return room;
}

function syncMessage(update) {
    const encoder = encoding.createEncoder();
    encoding.writeVarUint(encoder, MESSAGE_SYNC);
    syncProtocol.writeUpdate(encoder, update);
    return encoding.toUint8Array(encoder);
}

function awarenessMessage(awareness, clients) {
    const encoder = encoding.createEncoder();
    encoding.writeVarUint(encoder, MESSAGE_AWARENESS);
    encoding.writeVarUint8Array(encoder, awarenessProtocol.encodeAwarenessUpdate(awareness, clients));
    return encoding.toUint8Array(encoder);
}

function send(socket, message) {
    if (socket.readyState === 1) socket.send(message, { binary: true });
}

function broadcast(room, message, origin = null) {
    for (const socket of room.clients.keys()) {
        if (socket !== origin) send(socket, message);
    }
}

function scheduleCheckpoint(room) {
    room.dirty = true;
    if (room.checkpointTimer) clearTimeout(room.checkpointTimer);
    room.checkpointTimer = setTimeout(() => checkpoint(room), CHECKPOINT_DEBOUNCE_MS);
    if (!room.maxTimer) room.maxTimer = setTimeout(() => checkpoint(room), CHECKPOINT_MAX_MS);
}

async function checkpoint(room) {
    if (!room.dirty) return { ok: true, hash: room.hash, unchanged: true };
    if (room.checkpointRunning) return room.checkpointPromise;
    room.checkpointRunning = true;
    if (room.checkpointTimer) clearTimeout(room.checkpointTimer);
    if (room.maxTimer) clearTimeout(room.maxTimer);
    room.checkpointTimer = null;
    room.maxTimer = null;
    const content = room.doc.getText('monaco').toString();
    const revision = room.revision;
    room.checkpointPromise = (async () => {
    try {
        const result = await internalRequest('/api/realtime/checkpoint', {
            method: 'POST',
            body: JSON.stringify({
                user_uuid: room.checkpointTicket.user_uuid,
                project_uuid: room.checkpointTicket.project_uuid,
                environment_uuid: room.checkpointTicket.environment_uuid,
                path: room.checkpointTicket.path,
                room: room.checkpointTicket.room_id,
                content,
                expected_hash: room.hash
            })
        });
        room.hash = result.hash;
        if (room.revision === revision) {
            room.dirty = false;
        } else {
            scheduleCheckpoint(room);
        }
        return { ok: true, hash: room.hash, unchanged: false };
    } catch (error) {
        console.error('Realtime checkpoint failed:', error.message);
        if (error.status === 403) {
            room.dirty = false;
            for (const socket of room.clients.keys()) socket.close(1008, 'Authorization revoked');
        } else {
            room.checkpointTimer = setTimeout(() => checkpoint(room), 5000);
        }
        return { ok: false, status: error.status || 500, error: error.message };
    } finally {
        room.checkpointRunning = false;
    }
    })();
    return room.checkpointPromise;
}

function attach(socket, room, ticket) {
    room.clients.set(socket, {
        awarenessIds: new Set(),
        ticket,
        controlToken: ticket.control_token
    });

    const encoder = encoding.createEncoder();
    encoding.writeVarUint(encoder, MESSAGE_SYNC);
    syncProtocol.writeSyncStep1(encoder, room.doc);
    send(socket, encoding.toUint8Array(encoder));

    const awarenessClients = Array.from(room.awareness.getStates().keys());
    if (awarenessClients.length) send(socket, awarenessMessage(room.awareness, awarenessClients));

    socket.on('message', (data, isBinary) => {
        if (!isBinary) {
            try {
                if (JSON.parse(data.toString()).type === 'checkpoint') checkpoint(room);
            } catch {
                socket.close(1003, 'Invalid control message');
            }
            return;
        }
        const decoder = decoding.createDecoder(new Uint8Array(data));
        const type = decoding.readVarUint(decoder);
        if (type === MESSAGE_SYNC) {
            const reply = encoding.createEncoder();
            encoding.writeVarUint(reply, MESSAGE_SYNC);
            syncProtocol.readSyncMessage(decoder, reply, room.doc, socket);
            if (encoding.length(reply) > 1) send(socket, encoding.toUint8Array(reply));
        } else if (type === MESSAGE_AWARENESS) {
            awarenessProtocol.applyAwarenessUpdate(
                room.awareness,
                decoding.readVarUint8Array(decoder),
                socket
            );
        } else {
            socket.close(1003, 'Unsupported message');
        }
    });

    socket.on('close', async () => {
        const controlled = room.clients.get(socket);
        room.clients.delete(socket);
        if (controlled?.awarenessIds.size) {
            awarenessProtocol.removeAwarenessStates(
                room.awareness,
                Array.from(controlled.awarenessIds),
                socket
            );
        }
        if (room.clients.size === 0) {
            await checkpoint(room);
            if (!room.dirty && room.clients.size === 0) {
                room.doc.destroy();
                rooms.delete(room.ticket.room_id);
            }
        }
    });
}

const server = http.createServer(async (request, response) => {
    if (request.url === '/health') {
        return jsonResponse(response, 200, {
            ok: true,
            rooms: rooms.size,
            room_states: Array.from(rooms.values(), room => ({
                room: room.ticket.room_id.slice(0, 12),
                clients: room.clients.size,
                revision: room.revision,
                dirty: room.dirty
            }))
        });
    }

    const checkpointMatch = (request.url || '').match(/^\/checkpoint\/([a-f0-9]{64})$/);
    if (request.method === 'POST' && checkpointMatch) {
        const room = rooms.get(checkpointMatch[1]);
        const authorization = String(request.headers.authorization || '');
        const token = authorization.startsWith('Bearer ') ? authorization.slice(7) : '';
        const validToken = /^[a-f0-9]{64}$/.test(token);
        const allowed = validToken && room && Array.from(room.clients.values())
            .some(client => crypto.timingSafeEqual(
                Buffer.from(client.controlToken, 'hex'),
                Buffer.from(token, 'hex')
            ));
        if (!allowed) {
            return jsonResponse(response, 403, { error: 'Checkpoint não autorizado.' });
        }
        let result = await checkpoint(room);
        let attempts = 1;
        while (result.ok && room.dirty && attempts < 3) {
            result = await checkpoint(room);
            attempts += 1;
        }
        if (result.ok && room.dirty) {
            result = { ok: false, status: 409, error: 'O documento mudou durante o checkpoint.' };
        }
        return jsonResponse(response, result.ok ? 200 : result.status, result);
    }

    return jsonResponse(response, 404, { error: 'Not found' });
});

const webSocketServer = new WebSocketServer({ noServer: true, maxPayload: MAX_MESSAGE_BYTES });
server.on('upgrade', async (request, socket, head) => {
    try {
        const parsed = socketPath(request);
        if (!parsed) throw new Error('Invalid path');
        const token = parsed.url.searchParams.get('ticket') || '';
        const ticket = await consumeTicket(token, parsed.room);
        if (!ticket) throw new Error('Invalid ticket');
        const room = await roomFor(ticket);
        webSocketServer.handleUpgrade(request, socket, head, ws => attach(ws, room, ticket));
    } catch {
        socket.write('HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n');
        socket.destroy();
    }
});

server.listen(PORT, '0.0.0.0', () => {
    console.log(`Threeebs realtime listening on ${PORT}`);
});


let shuttingDown = false;
async function shutdown() {
    if (shuttingDown) return;
    shuttingDown = true;
    webSocketServer.close();
    for (const room of rooms.values()) {
        for (const socket of room.clients.keys()) socket.close(1012, 'Service restart');
    }
    await Promise.allSettled(Array.from(rooms.values(), room => checkpoint(room)));
    await redis.quit().catch(() => {});
    server.close(() => process.exit(0));
    setTimeout(() => process.exit(1), 10000).unref();
}

process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
