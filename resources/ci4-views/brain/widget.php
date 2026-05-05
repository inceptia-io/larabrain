<?php
/**
 * Brain Floating Widget — CodeIgniter 4
 *
 * Include this in your CI4 layout (e.g. the bottom of your master view):
 *
 *   <?php include ROOTPATH . 'vendor/inceptia-io/larabrain/resources/ci4-views/brain/widget.php'; ?>
 *
 * Or use the BrainController::widget() method in your routes.
 * The widget injects its own styles and JS — no build step required.
 */
?>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

<style>
    #brain-widget-btn {
        position: fixed;
        bottom: 24px;
        right: 24px;
        background: #3b4fd8;
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 10px 18px;
        font-size: 14px;
        font-weight: 500;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        cursor: pointer;
        z-index: 9998;
        box-shadow: 0 4px 16px rgba(0,0,0,0.4);
        transition: background 0.15s, transform 0.15s;
    }
    #brain-widget-btn:hover { background: #4a5ee8; transform: translateY(-1px); }

    #brain-panel {
        position: fixed;
        bottom: 74px;
        right: 24px;
        width: 420px;
        max-width: calc(100vw - 32px);
        height: 560px;
        max-height: calc(100vh - 100px);
        background: #1a1d27;
        border: 1px solid #2d3148;
        border-radius: 12px;
        box-shadow: 0 16px 48px rgba(0,0,0,0.6);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        color: #e2e8f0;
        overflow: hidden;
        transition: opacity 0.15s, transform 0.15s;
    }
    #brain-panel.brain-hidden { opacity: 0; transform: translateY(8px) scale(0.98); pointer-events: none; }

    .bw-header { display: flex; align-items: center; padding: 12px 16px; background: #14161f; border-bottom: 1px solid #2d3148; gap: 10px; flex-shrink: 0; }
    .bw-title { font-size: 14px; font-weight: 600; color: #fff; flex: 1; }
    .bw-close { background: transparent; border: none; color: #6b7280; cursor: pointer; font-size: 18px; line-height: 1; padding: 2px 4px; font-family: inherit; }
    .bw-close:hover { color: #e2e8f0; }

    .bw-messages { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 14px; }
    .bw-messages::-webkit-scrollbar { width: 3px; }
    .bw-messages::-webkit-scrollbar-thumb { background: #2d3148; border-radius: 2px; }

    .bw-empty { flex: 1; display: flex; align-items: center; justify-content: center; text-align: center; color: #4b5563; font-size: 13px; padding: 16px; line-height: 1.6; }

    .bw-msg { max-width: 100%; }
    .bw-msg.user { align-self: flex-end; }
    .bw-msg.assistant { align-self: flex-start; }

    .bw-bubble { padding: 9px 13px; border-radius: 10px; font-size: 13px; line-height: 1.55; word-break: break-word; }
    .bw-msg.user .bw-bubble { white-space: pre-wrap; background: #3b4fd8; color: #fff; border-bottom-right-radius: 3px; }
    .bw-msg.assistant .bw-bubble { background: #0f1117; color: #e2e8f0; border: 1px solid #2d3148; border-bottom-left-radius: 3px; }
    .bw-msg.error .bw-bubble { background: #2d1515; color: #f87171; border: 1px solid #4d2020; }

    .bw-msg.assistant .bw-bubble p { margin: 0 0 0.5em; }
    .bw-msg.assistant .bw-bubble p:last-child { margin-bottom: 0; }
    .bw-msg.assistant .bw-bubble h1, .bw-msg.assistant .bw-bubble h2, .bw-msg.assistant .bw-bubble h3 { margin: 0.6em 0 0.2em; font-size: 0.95em; font-weight: 700; color: #f1f5f9; }
    .bw-msg.assistant .bw-bubble ul, .bw-msg.assistant .bw-bubble ol { margin: 0.2em 0 0.5em; padding-left: 1.3em; }
    .bw-msg.assistant .bw-bubble li { margin: 0.15em 0; }
    .bw-msg.assistant .bw-bubble a { color: #60a5fa; text-decoration: underline; }
    .bw-msg.assistant .bw-bubble a:hover { color: #93c5fd; }
    .bw-msg.assistant .bw-bubble code { background: #1a1d27; border: 1px solid #2d3148; border-radius: 3px; padding: 1px 4px; font-size: 11px; font-family: 'SF Mono', 'Fira Code', monospace; color: #a5f3fc; }
    .bw-msg.assistant .bw-bubble pre { background: #1a1d27; border: 1px solid #2d3148; border-radius: 5px; padding: 8px 10px; overflow-x: auto; margin: 0.4em 0; }
    .bw-msg.assistant .bw-bubble pre code { background: none; border: none; padding: 0; }
    .bw-msg.assistant .bw-bubble strong { color: #f1f5f9; }
    .bw-msg.assistant .bw-bubble blockquote { border-left: 3px solid #3b4fd8; margin: 0.3em 0; padding-left: 10px; color: #9ca3af; }

    .bw-meta { font-size: 10px; color: #4b5563; margin-top: 4px; padding: 0 3px; }
    .bw-msg.user .bw-meta { text-align: right; }

    .bw-dots span { display: inline-block; width: 4px; height: 4px; background: #6b7280; border-radius: 50%; margin: 0 1px; animation: bw-blink 1.2s infinite; }
    .bw-dots span:nth-child(2) { animation-delay: 0.2s; }
    .bw-dots span:nth-child(3) { animation-delay: 0.4s; }
    @keyframes bw-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

    .bw-input-area { border-top: 1px solid #2d3148; padding: 10px 12px; flex-shrink: 0; background: #14161f; }
    .bw-row { display: flex; gap: 8px; align-items: flex-end; }
    .bw-textarea { flex: 1; background: #0f1117; border: 1px solid #2d3148; border-radius: 7px; padding: 8px 11px; font-size: 13px; color: #e2e8f0; resize: none; outline: none; font-family: inherit; line-height: 1.5; min-height: 38px; max-height: 100px; transition: border-color 0.15s; }
    .bw-textarea::placeholder { color: #4b5563; }
    .bw-textarea:focus { border-color: #3b4fd8; }
    .bw-send { background: #3b4fd8; color: #fff; border: none; border-radius: 7px; padding: 8px 14px; font-size: 13px; font-weight: 500; cursor: pointer; height: 38px; font-family: inherit; transition: background 0.15s; white-space: nowrap; }
    .bw-send:hover { background: #4a5ee8; }
    .bw-send:disabled { background: #2d3148; color: #6b7280; cursor: not-allowed; }
</style>

<button id="brain-widget-btn" onclick="brainWidgetToggle()">Ask Brain</button>

<div id="brain-panel" class="brain-hidden">
    <div class="bw-header">
        <span class="bw-title">Brain</span>
        <button class="bw-close" onclick="brainWidgetToggle()" title="Close">&#x2715;</button>
    </div>
    <div class="bw-messages" id="bw-messages">
        <div class="bw-empty" id="bw-empty">Ask anything about your application.</div>
    </div>
    <div class="bw-input-area">
        <div class="bw-row">
            <textarea id="bw-textarea" class="bw-textarea" rows="1" placeholder="Ask a question..."></textarea>
            <button class="bw-send" id="bw-send" onclick="bwSend()">Ask</button>
        </div>
    </div>
</div>

<script>
(function () {
    var panel    = document.getElementById('brain-panel');
    var messages = document.getElementById('bw-messages');
    var emptyEl  = document.getElementById('bw-empty');
    var textarea = document.getElementById('bw-textarea');
    var sendBtn  = document.getElementById('bw-send');

    var busy     = false;
    var endpoint = '<?= site_url('brain/ask') ?>';
    var csrfToken = '<?= csrf_token() ?>';
    var csrfHash  = '<?= csrf_hash() ?>';

    window.brainWidgetToggle = function () {
        panel.classList.toggle('brain-hidden');
        if (!panel.classList.contains('brain-hidden')) textarea.focus();
    };

    textarea.addEventListener('input', function () {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
    });
    textarea.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); bwSend(); }
    });

    function removeEmpty() {
        if (emptyEl && emptyEl.parentNode) { emptyEl.parentNode.removeChild(emptyEl); emptyEl = null; }
    }

    function addMsg(role, content, meta) {
        removeEmpty();
        var w = document.createElement('div');
        w.className = 'bw-msg ' + role;
        var b = document.createElement('div');
        b.className = 'bw-bubble';
        if (role === 'assistant') { b.innerHTML = content; } else { b.textContent = content; }
        w.appendChild(b);
        if (meta) { var m = document.createElement('div'); m.className = 'bw-meta'; m.textContent = meta; w.appendChild(m); }
        messages.appendChild(w);
        messages.scrollTop = messages.scrollHeight;
        return w;
    }

    function addThinking() {
        removeEmpty();
        var w = document.createElement('div');
        w.className = 'bw-msg assistant';
        var b = document.createElement('div');
        b.className = 'bw-bubble';
        b.innerHTML = 'Thinking <span class="bw-dots"><span></span><span></span><span></span></span>';
        w.appendChild(b);
        messages.appendChild(w);
        messages.scrollTop = messages.scrollHeight;
        return w;
    }

    window.bwSend = function () {
        if (busy) return;
        var q = textarea.value.trim();
        if (!q) return;

        busy = true;
        sendBtn.disabled = true;
        textarea.value = '';
        textarea.style.height = 'auto';

        addMsg('user', q);
        var thinking = addThinking();

        var body = { question: q };
        body[csrfToken] = csrfHash;

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (thinking.parentNode) thinking.parentNode.removeChild(thinking);
            if (data.error) {
                addMsg('error', data.error);
            } else {
                var meta = [data.intent, data.driver, Math.round(data.elapsed_ms) + 'ms'].filter(Boolean).join('  |  ');
                var md = (typeof marked !== 'undefined')
                    ? marked.parse(data.answer)
                    : data.answer.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                addMsg('assistant', md, meta);
            }
        })
        .catch(function () {
            if (thinking.parentNode) thinking.parentNode.removeChild(thinking);
            addMsg('error', 'Could not reach the server. Please try again.');
        })
        .finally(function () { busy = false; sendBtn.disabled = false; });
    };
}());
</script>
