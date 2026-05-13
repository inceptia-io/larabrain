<?php
/**
 * Brain Chat Page — CodeIgniter 3
 *
 * Loaded via BrainController::chat() or included directly:
 *
 *   include FCPATH . 'vendor/inceptia-io/larabrain/resources/ci3-views/brain/chat.php';
 */
$CI       = &get_instance();
$CI->load->helper('url');
$_csrfName  = $CI->security->get_csrf_token_name();
$_csrfHash  = $CI->security->get_csrf_hash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brain - Application Assistant</title>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f1117;
            color: #e2e8f0;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .brain-header {
            background: #1a1d27;
            border-bottom: 1px solid #2d3148;
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
        }

        .brain-header h1 { font-size: 16px; font-weight: 600; color: #fff; letter-spacing: 0.02em; }
        .brain-header .subtitle { font-size: 12px; color: #6b7280; margin-left: auto; }

        .brain-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .brain-messages::-webkit-scrollbar { width: 4px; }
        .brain-messages::-webkit-scrollbar-thumb { background: #2d3148; border-radius: 2px; }

        .message { max-width: 780px; width: 100%; }
        .message.user { align-self: flex-end; }
        .message.assistant { align-self: flex-start; }

        .message-bubble {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }

        .message.user .message-bubble {
            background: #3b4fd8;
            color: #fff;
            border-bottom-right-radius: 3px;
            white-space: pre-wrap;
        }

        .message.assistant .message-bubble {
            background: #1a1d27;
            color: #e2e8f0;
            border-bottom-left-radius: 3px;
            border: 1px solid #2d3148;
        }

        .message.assistant .message-bubble p { margin: 0 0 0.6em; }
        .message.assistant .message-bubble p:last-child { margin-bottom: 0; }
        .message.assistant .message-bubble h1,
        .message.assistant .message-bubble h2,
        .message.assistant .message-bubble h3 { margin: 0.8em 0 0.3em; font-size: 1em; font-weight: 700; color: #f1f5f9; }
        .message.assistant .message-bubble h1:first-child,
        .message.assistant .message-bubble h2:first-child,
        .message.assistant .message-bubble h3:first-child { margin-top: 0; }
        .message.assistant .message-bubble ul, .message.assistant .message-bubble ol { margin: 0.3em 0 0.6em; padding-left: 1.4em; }
        .message.assistant .message-bubble li { margin: 0.2em 0; }
        .message.assistant .message-bubble a { color: #60a5fa; text-decoration: underline; }
        .message.assistant .message-bubble a:hover { color: #93c5fd; }
        .message.assistant .message-bubble code {
            background: #0f1117;
            border: 1px solid #2d3148;
            border-radius: 3px;
            padding: 1px 5px;
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 12px;
            color: #a5f3fc;
        }
        .message.assistant .message-bubble pre {
            background: #0f1117;
            border: 1px solid #2d3148;
            border-radius: 6px;
            padding: 12px 14px;
            overflow-x: auto;
            margin: 0.6em 0;
        }
        .message.assistant .message-bubble pre code { background: none; border: none; padding: 0; }
        .message.assistant .message-bubble strong { color: #f1f5f9; }
        .message.assistant .message-bubble blockquote { border-left: 3px solid #3b4fd8; margin: 0.4em 0; padding-left: 12px; color: #9ca3af; }
        .message.assistant .message-bubble hr { border: none; border-top: 1px solid #2d3148; margin: 0.8em 0; }

        .message-meta { font-size: 11px; color: #4b5563; margin-top: 5px; padding: 0 4px; }
        .message.user .message-meta { text-align: right; }

        .empty-state { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: #4b5563; }
        .empty-state p { font-size: 14px; }
        .empty-state .hint { font-size: 12px; }

        .thinking .message-bubble { display: flex; align-items: center; gap: 6px; color: #6b7280; font-style: italic; font-size: 13px; }
        .dots span { display: inline-block; width: 5px; height: 5px; background: #6b7280; border-radius: 50%; animation: blink 1.2s infinite; }
        .dots span:nth-child(2) { animation-delay: 0.2s; }
        .dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }

        .brain-input-area {
            background: #1a1d27;
            border-top: 1px solid #2d3148;
            padding: 16px 24px;
            flex-shrink: 0;
        }

        .input-row { display: flex; gap: 10px; align-items: flex-end; }

        .question-field {
            flex: 1;
            background: #0f1117;
            border: 1px solid #2d3148;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            color: #e2e8f0;
            resize: none;
            outline: none;
            font-family: inherit;
            line-height: 1.5;
            min-height: 44px;
            max-height: 140px;
            transition: border-color 0.15s;
        }

        .question-field::placeholder { color: #4b5563; }
        .question-field:focus { border-color: #3b4fd8; }

        .send-btn {
            background: #3b4fd8;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            white-space: nowrap;
            height: 44px;
            transition: background 0.15s;
        }

        .send-btn:hover { background: #4a5ee8; }
        .send-btn:disabled { background: #2d3148; color: #6b7280; cursor: not-allowed; }

        .input-hint { font-size: 11px; color: #4b5563; margin-top: 8px; }

        .message.error .message-bubble { background: #2d1515; color: #f87171; border: 1px solid #4d2020; }
    </style>
</head>
<body>

<div class="brain-header">
    <h1>Brain</h1>
    <span class="subtitle">Application Assistant</span>
</div>

<div class="brain-messages" id="messages">
    <div class="empty-state" id="empty-state">
        <p>Ask anything about your application.</p>
        <span class="hint">Try: "How do I create a category?" or "Show me the product routes."</span>
    </div>
</div>

<div class="brain-input-area">
    <div class="input-row">
        <textarea
            id="question-field"
            class="question-field"
            placeholder="Ask a question about your application..."
            rows="1"
        ></textarea>
        <button class="send-btn" id="send-btn" onclick="sendQuestion()">Ask</button>
    </div>
    <div class="input-hint">Press Enter to send, Shift+Enter for a new line.</div>
</div>

<script>
    const messagesEl  = document.getElementById('messages');
    const emptyState  = document.getElementById('empty-state');
    const questionEl  = document.getElementById('question-field');
    const sendBtn     = document.getElementById('send-btn');
    const askEndpoint = '<?= site_url('brain/ask') ?>';
    const csrfToken   = '<?= htmlspecialchars($_csrfName, ENT_QUOTES, 'UTF-8') ?>';
    const csrfValue   = '<?= htmlspecialchars($_csrfHash, ENT_QUOTES, 'UTF-8') ?>';

    let isBusy = false;

    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 140) + 'px';
    }

    questionEl.addEventListener('input', () => autoResize(questionEl));
    questionEl.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendQuestion(); }
    });

    function appendMessage(role, html, meta) {
        if (emptyState) emptyState.remove();

        const wrapper = document.createElement('div');
        wrapper.className = 'message ' + role;

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';

        if (role === 'assistant') {
            bubble.innerHTML = html;
        } else {
            bubble.textContent = html;
        }

        wrapper.appendChild(bubble);

        if (meta) {
            const metaEl = document.createElement('div');
            metaEl.className = 'message-meta';
            metaEl.textContent = meta;
            wrapper.appendChild(metaEl);
        }

        messagesEl.appendChild(wrapper);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return wrapper;
    }

    function appendThinking() {
        if (emptyState) emptyState.remove();
        const wrapper = document.createElement('div');
        wrapper.className = 'message assistant thinking';
        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';
        bubble.innerHTML = 'Thinking <span class="dots"><span></span><span></span><span></span></span>';
        wrapper.appendChild(bubble);
        messagesEl.appendChild(wrapper);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        return wrapper;
    }

    async function sendQuestion() {
        if (isBusy) return;
        const question = questionEl.value.trim();
        if (!question) return;

        isBusy = true;
        sendBtn.disabled = true;
        questionEl.value = '';
        autoResize(questionEl);

        appendMessage('user', question);
        const thinking = appendThinking();

        try {
            const body = { question };
            body[csrfToken] = csrfValue;

            const res = await fetch(askEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await res.json();
            thinking.remove();

            if (data.error) {
                appendMessage('error', data.error);
            } else {
                const meta = [data.intent, data.driver, Math.round(data.elapsed_ms) + 'ms'].filter(Boolean).join('  |  ');
                const md = (typeof marked !== 'undefined')
                    ? marked.parse(data.answer)
                    : data.answer.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
                appendMessage('assistant', md, meta);
            }
        } catch (e) {
            thinking.remove();
            appendMessage('error', 'Could not reach the server. Please try again.');
        }

        isBusy = false;
        sendBtn.disabled = false;
        questionEl.focus();
    }
</script>

</body>
</html>
