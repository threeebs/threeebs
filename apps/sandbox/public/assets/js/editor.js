(function () {
    'use strict';

    const root = document.querySelector('[data-threeebs-editor]');
    if (!root) return;

    const projectUuid = root.dataset.projectUuid;
    const csrf = root.dataset.csrf;
    const treeElement = document.getElementById('project-tree');
    const pathElement = document.getElementById('editor-path');
    const statusElement = document.getElementById('editor-status');
    const saveButton = document.getElementById('save-file');
    const renameButton = document.getElementById('rename-item');
    const deleteButton = document.getElementById('delete-item');
    let editor = null;
    let selected = null;
    let currentPath = '';
    let currentHash = '';
    let dirty = false;
    let realtimeSession = null;
    let realtimeGeneration = 0;

    function setStatus(message, error) {
        statusElement.textContent = message;
        statusElement.classList.toggle('is-error', Boolean(error));
    }

    async function responseJson(response) {
        const payload = await response.json().catch(() => ({ error: 'Resposta inválida do servidor.' }));
        if (!response.ok) throw new Error(payload.error || 'Não foi possível concluir a operação.');
        return payload;
    }

    function apiGet(path, params) {
        const url = new URL(path, window.location.origin);
        Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
        return fetch(url, { credentials: 'same-origin' }).then(responseJson);
    }

    function apiPost(action, values) {
        const body = new FormData();
        body.set('_action', action);
        body.set('csrf', csrf);
        body.set('projeto_uuid', projectUuid);
        Object.entries(values || {}).forEach(([key, value]) => body.set(key, value));
        return fetch('/api/editor', {
            method: 'POST',
            credentials: 'same-origin',
            body
        }).then(responseJson);
    }

    function languageFor(path) {
        const extension = path.split('.').pop().toLowerCase();
        return ({
            html: 'html', htm: 'html', css: 'css', js: 'javascript', mjs: 'javascript',
            cjs: 'javascript', json: 'json', md: 'markdown', xml: 'xml', svg: 'xml', php: 'php', txt: 'plaintext'
        })[extension] || 'plaintext';
    }

    function selectedDirectory() {
        if (!selected) return '';
        if (selected.type === 'directory') return selected.path;
        const parts = selected.path.split('/');
        parts.pop();
        return parts.join('/');
    }

    function suggestedPath(label) {
        const directory = selectedDirectory();
        const value = window.prompt(label, directory ? directory + '/' : '');
        return value === null ? '' : value.trim();
    }

    function selectTreeButton(button, node) {
        treeElement.querySelectorAll('.tree-item').forEach(item => item.classList.remove('is-selected'));
        button.classList.add('is-selected');
        selected = node;
        renameButton.disabled = false;
        deleteButton.disabled = false;
    }

    function disconnectRealtime() {
        realtimeGeneration += 1;
        if (realtimeSession) realtimeSession.destroy();
        realtimeSession = null;
    }

    function connectRealtime(model, path) {
        if (root.dataset.realtime !== 'enabled' || !window.ThreeebsRealtime) return;
        const generation = ++realtimeGeneration;
        realtimeSession = window.ThreeebsRealtime.connect({
            editor,
            model,
            getTicket: () => apiPost('editor_realtime_ticket', { path }),
            onStatus(state, error) {
                if (generation !== realtimeGeneration) return;
                if (state === 'connected') setStatus('Colaboração em tempo real conectada.');
                if (state === 'disconnected') setStatus('Reconectando colaboração…');
                if (state === 'saving') setStatus('Salvando checkpoint…');
                if (state === 'saved') setStatus('Checkpoint confirmado pelo Storage Service.');
                if (state === 'error') setStatus(error?.message || 'Falha na colaboração em tempo real.', true);
            }
        });
    }

    async function openFile(button, node) {
        if (dirty && !window.confirm('Descartar alterações ainda não salvas?')) return;
        disconnectRealtime();
        selectTreeButton(button, node);
        if (!node.editable) {
            currentPath = '';
            currentHash = '';
            dirty = false;
            editor.setValue('');
            saveButton.disabled = true;
            pathElement.textContent = node.path;
            setStatus('Arquivo exibido na árvore, mas não editável nesta Alpha.', true);
            return;
        }
        setStatus('Abrindo arquivo…');
        try {
            const payload = await apiGet('/api/editor/file', { uuid: projectUuid, path: node.path });
            currentPath = payload.file.path;
            currentHash = payload.file.hash;
            dirty = false;
            pathElement.textContent = currentPath;
            const uri = window.monaco.Uri.parse('file:///' + currentPath);
            const previous = editor.getModel();
            editor.setModel(null);
            if (previous) previous.dispose();
            const model = window.monaco.editor.createModel(payload.file.content, languageFor(currentPath), uri);
            editor.setModel(model);
            model.setEOL(window.monaco.editor.EndOfLineSequence.LF);
            dirty = false;
            saveButton.disabled = false;
            setStatus('Arquivo carregado.');
            connectRealtime(model, currentPath);
        } catch (error) {
            setStatus(error.message, true);
        }
    }

    function nodeList(nodes) {
        const list = document.createElement('ul');
        nodes.forEach(node => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tree-item';
            if (node.type === 'file' && !node.editable) button.classList.add('is-readonly');
            button.textContent = (node.type === 'directory' ? '▸ ' : '• ') + node.name;
            button.title = node.path;
            button.addEventListener('click', () => {
                if (node.type === 'directory') {
                    selectTreeButton(button, node);
                    const childList = item.querySelector(':scope > ul');
                    if (childList) childList.hidden = !childList.hidden;
                } else {
                    openFile(button, node);
                }
            });
            item.appendChild(button);
            if (node.type === 'directory' && node.children) item.appendChild(nodeList(node.children));
            list.appendChild(item);
        });
        return list;
    }

    async function refreshTree(preferredPath) {
        try {
            const payload = await apiGet('/api/editor/tree', { uuid: projectUuid });
            treeElement.replaceChildren(nodeList(payload.tree));
            selected = null;
            renameButton.disabled = true;
            deleteButton.disabled = true;
            if (preferredPath) {
                const button = Array.from(treeElement.querySelectorAll('.tree-item'))
                    .find(item => item.title === preferredPath);
                if (button) button.click();
            }
        } catch (error) {
            setStatus(error.message, true);
        }
    }

    async function saveFile() {
        if (!editor || !currentPath) return;
        if (realtimeSession) {
            saveButton.disabled = true;
            try {
                const wasDirty = dirty;
                const payload = await realtimeSession.requestCheckpoint();
                if (wasDirty && payload.unchanged === true && payload.hash === currentHash) {
                    throw new Error('A alteração ainda não chegou ao documento colaborativo.');
                }
                if (payload.hash) currentHash = payload.hash;
                dirty = false;
                setStatus('Checkpoint confirmado pelo Storage Service.');
            } catch (error) {
                setStatus(error.message, true);
            } finally {
                saveButton.disabled = false;
            }
            return;
        }
        saveButton.disabled = true;
        setStatus('Salvando…');
        try {
            const payload = await apiPost('editor_save', {
                path: currentPath,
                content: editor.getValue(),
                expected_hash: currentHash
            });
            currentHash = payload.hash;
            dirty = false;
            setStatus('Arquivo salvo no Sandbox.');
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            saveButton.disabled = false;
        }
    }

    document.getElementById('create-file').addEventListener('click', async () => {
        const path = suggestedPath('Caminho do novo arquivo (ex.: css/style.css)');
        if (!path) return;
        try {
            await apiPost('editor_create_file', { path });
            await refreshTree(path);
            setStatus('Arquivo criado.');
        } catch (error) { setStatus(error.message, true); }
    });

    document.getElementById('create-directory').addEventListener('click', async () => {
        const path = suggestedPath('Caminho da nova pasta');
        if (!path) return;
        try {
            await apiPost('editor_create_directory', { path });
            await refreshTree(path);
            setStatus('Pasta criada.');
        } catch (error) { setStatus(error.message, true); }
    });

    renameButton.addEventListener('click', async () => {
        if (!selected) return;
        const destination = window.prompt('Novo caminho', selected.path);
        if (destination === null || !destination.trim() || destination.trim() === selected.path) return;
        try {
            await apiPost('editor_rename', { path: selected.path, destination: destination.trim() });
            if (currentPath === selected.path || currentPath.startsWith(selected.path + '/')) {
                disconnectRealtime();
                currentPath = '';
                currentHash = '';
                editor.setValue('');
                saveButton.disabled = true;
            }
            await refreshTree(destination.trim());
            setStatus('Item renomeado.');
        } catch (error) { setStatus(error.message, true); }
    });

    deleteButton.addEventListener('click', async () => {
        if (!selected || !window.confirm('Excluir “' + selected.path + '”? Esta ação não pode ser desfeita.')) return;
        const deleting = selected.path;
        try {
            await apiPost('editor_delete', { path: deleting });
            if (currentPath === deleting) {
                disconnectRealtime();
                currentPath = '';
                currentHash = '';
                editor.setValue('');
                pathElement.textContent = 'Nenhum arquivo aberto';
                saveButton.disabled = true;
            }
            await refreshTree('');
            setStatus('Item excluído.');
        } catch (error) { setStatus(error.message, true); }
    });

    saveButton.addEventListener('click', saveFile);
    window.addEventListener('beforeunload', disconnectRealtime);

    window.require.config({ paths: { vs: '/vendor/monaco/vs' } });
    window.require(['vs/editor/editor.main'], function () {
        editor = window.monaco.editor.create(document.getElementById('monaco-editor'), {
            value: '', language: 'plaintext', theme: 'vs-dark', automaticLayout: true,
            minimap: { enabled: true }, fontSize: 14, tabSize: 2
        });
        editor.onDidChangeModelContent(() => {
            if (!currentPath) return;
            dirty = true;
            setStatus('Alterações não salvas.');
        });
        editor.addCommand(window.monaco.KeyMod.CtrlCmd | window.monaco.KeyCode.KeyS, saveFile);
        refreshTree('index.html');
    }, function (error) {
        setStatus('Não foi possível carregar o Threeebs Editor: ' + error.message, true);
    });
}());
