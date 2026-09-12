import * as Y from 'yjs';
import { WebsocketProvider } from 'y-websocket';
import { MonacoBinding } from 'y-monaco';

const RECONNECT_MIN_MS = 750;
const RECONNECT_MAX_MS = 10000;

export function connect(options) {
    let provider = null;
    let binding = null;
    let document = null;
    let stopped = false;
    let reconnectTimer = null;
    let reconnectDelay = RECONNECT_MIN_MS;
    let activeAuthorization = null;

    const status = typeof options.onStatus === 'function' ? options.onStatus : () => {};

    async function start() {
        if (stopped) return;
        status('connecting');
        try {
            const authorization = await options.getTicket();
            if (stopped) return;
            activeAuthorization = authorization;

            const endpoint = new URL(authorization.url, window.location.origin);
            endpoint.protocol = endpoint.protocol === 'https:' ? 'wss:' : 'ws:';
            document = new Y.Doc();
            const sharedText = document.getText('monaco');
            provider = new WebsocketProvider(
                endpoint.toString().replace(/\/$/, ''),
                authorization.room,
                document,
                {
                    params: { ticket: authorization.ticket },
                    connect: true,
                    maxBackoffTime: 0
                }
            );

            provider.awareness.setLocalStateField('user', authorization.user);

            provider.once('sync', (synchronized) => {
                if (!synchronized || stopped) return;
                options.model.setEOL(window.monaco.editor.EndOfLineSequence.LF);
                binding = new MonacoBinding(
                    sharedText,
                    options.model,
                    new Set([options.editor]),
                    provider.awareness
                );
                reconnectDelay = RECONNECT_MIN_MS;
                status('connected');
            });

            provider.on('status', (event) => {
                if (!stopped && event.status === 'disconnected') {
                    status('disconnected');
                }
            });

            provider.on('connection-error', () => scheduleReconnect());
            provider.on('connection-close', () => scheduleReconnect());
        } catch (error) {
            status('error', error);
            scheduleReconnect();
        }
    }

    function cleanupConnection() {
        if (binding) binding.destroy();
        binding = null;
        if (provider) {
            provider.shouldConnect = false;
            provider.destroy();
        }
        provider = null;
        activeAuthorization = null;
        if (document) document.destroy();
        document = null;
    }

    function scheduleReconnect() {
        if (stopped || reconnectTimer) return;
        cleanupConnection();
        reconnectTimer = window.setTimeout(() => {
            reconnectTimer = null;
            reconnectDelay = Math.min(reconnectDelay * 2, RECONNECT_MAX_MS);
            start();
        }, reconnectDelay);
    }

    async function requestCheckpoint() {
        if (!provider || provider.ws?.readyState !== WebSocket.OPEN || !activeAuthorization) {
            throw new Error('A colaboração ainda não está conectada.');
        }
        status('saving');
        const endpoint = new URL(activeAuthorization.checkpoint_url, window.location.origin);
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + activeAuthorization.control_token
            }
        });
        const payload = await response.json().catch(() => ({
            error: 'Resposta de checkpoint inválida.'
        }));
        if (!response.ok || payload.ok !== true) {
            const error = new Error(payload.error || 'O checkpoint não foi confirmado.');
            status('error', error);
            throw error;
        }
        status('saved');
        return payload;
    }

    function destroy() {
        stopped = true;
        if (reconnectTimer) window.clearTimeout(reconnectTimer);
        cleanupConnection();
        status('stopped');
    }

    start();
    return { destroy, requestCheckpoint };
}
