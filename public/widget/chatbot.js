/**
 * Chatbot Widget - Full Implementation
 *
 * Usage Method 1 (Data Attributes):
 * <script src="https://your-domain.com/widget/chatbot.js"
 *         data-chatbot-key="your-api-key"
 *         data-chatbot-position="bottom-right"
 *         data-chatbot-color="#4F46E5"></script>
 *
 * Usage Method 2 (JavaScript):
 * <script src="https://your-domain.com/widget/chatbot.js"></script>
 * <script>
 *   ChatbotWidget.init({ apiKey: 'your-api-key' });
 * </script>
 */
(function() {
    'use strict';

    // Prevent multiple initializations
    if (window.ChatbotWidget) return;

    const ChatbotWidget = {
        config: {
            apiKey: null,
            baseUrl: null,
            position: 'bottom-right',
            primaryColor: '#4F46E5',
            welcomeMessage: 'Hello! How can I help you today?',
            botName: 'Assistant',
            botAvatar: null,
        },
        state: {
            isOpen: false,
            isLoading: false,
            conversationId: null,
            sessionId: null,
            messages: [],
        },
        elements: {},

        init: function(options) {
            if (!options.apiKey) {
                console.error('[Chatbot] API key is required');
                return;
            }

            this.config = { ...this.config, ...options };
            this.config.baseUrl = options.baseUrl || this.detectBaseUrl();

            this.injectStyles();
            this.createWidget();
            this.attachEventListeners();
            this.initializeWidget();
        },

        detectBaseUrl: function() {
            const script = document.querySelector('script[data-chatbot-key]');
            if (script && script.src) {
                const url = new URL(script.src);
                return url.origin;
            }
            return window.location.origin;
        },

        injectStyles: function() {
            const styles = `
                .chatbot-widget * {
                    box-sizing: border-box;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                }
                .chatbot-launcher {
                    position: fixed;
                    ${this.config.position === 'bottom-left' ? 'left: 20px;' : 'right: 20px;'}
                    bottom: 20px;
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    background: ${this.config.primaryColor};
                    border: none;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: transform 0.2s, box-shadow 0.2s;
                    z-index: 999998;
                }
                .chatbot-launcher:hover {
                    transform: scale(1.05);
                    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
                }
                .chatbot-launcher svg {
                    width: 28px;
                    height: 28px;
                    fill: white;
                }
                .chatbot-launcher.open svg.chat-icon { display: none; }
                .chatbot-launcher.open svg.close-icon { display: block; }
                .chatbot-launcher svg.close-icon { display: none; }

                .chatbot-container {
                    position: fixed;
                    ${this.config.position === 'bottom-left' ? 'left: 20px;' : 'right: 20px;'}
                    bottom: 90px;
                    width: 380px;
                    height: 550px;
                    max-height: calc(100vh - 120px);
                    background: #fff;
                    border-radius: 16px;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                    display: none;
                    flex-direction: column;
                    overflow: hidden;
                    z-index: 999999;
                }
                .chatbot-container.open {
                    display: flex;
                    animation: chatbot-slide-up 0.3s ease;
                }
                @keyframes chatbot-slide-up {
                    from {
                        opacity: 0;
                        transform: translateY(20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .chatbot-header {
                    background: ${this.config.primaryColor};
                    color: white;
                    padding: 16px 20px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                .chatbot-header-avatar {
                    width: 40px;
                    height: 40px;
                    border-radius: 50%;
                    background: rgba(255,255,255,0.2);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 18px;
                }
                .chatbot-header-info h3 {
                    margin: 0;
                    font-size: 16px;
                    font-weight: 600;
                }
                .chatbot-header-info p {
                    margin: 2px 0 0;
                    font-size: 12px;
                    opacity: 0.9;
                }

                .chatbot-messages {
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    background: #f9fafb;
                }

                .chatbot-message {
                    max-width: 85%;
                    padding: 12px 16px;
                    border-radius: 16px;
                    font-size: 14px;
                    line-height: 1.5;
                    word-wrap: break-word;
                }
                .chatbot-message.bot {
                    background: white;
                    align-self: flex-start;
                    border-bottom-left-radius: 4px;
                    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
                }
                .chatbot-message.user {
                    background: ${this.config.primaryColor};
                    color: white;
                    align-self: flex-end;
                    border-bottom-right-radius: 4px;
                }
                .chatbot-message.typing {
                    background: white;
                    align-self: flex-start;
                    border-bottom-left-radius: 4px;
                }
                .chatbot-typing-indicator {
                    display: flex;
                    gap: 4px;
                    padding: 4px 0;
                }
                .chatbot-typing-indicator span {
                    width: 8px;
                    height: 8px;
                    background: #94a3b8;
                    border-radius: 50%;
                    animation: chatbot-typing 1.4s infinite;
                }
                .chatbot-typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
                .chatbot-typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
                @keyframes chatbot-typing {
                    0%, 60%, 100% { transform: translateY(0); }
                    30% { transform: translateY(-4px); }
                }

                .chatbot-input-area {
                    padding: 16px;
                    background: white;
                    border-top: 1px solid #e5e7eb;
                }
                .chatbot-input-form {
                    display: flex;
                    gap: 8px;
                }
                .chatbot-input {
                    flex: 1;
                    padding: 12px 16px;
                    border: 1px solid #e5e7eb;
                    border-radius: 24px;
                    font-size: 14px;
                    outline: none;
                    transition: border-color 0.2s;
                }
                .chatbot-input:focus {
                    border-color: ${this.config.primaryColor};
                }
                .chatbot-input:disabled {
                    background: #f3f4f6;
                    cursor: not-allowed;
                }
                .chatbot-send-btn {
                    width: 44px;
                    height: 44px;
                    border-radius: 50%;
                    background: ${this.config.primaryColor};
                    border: none;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: background 0.2s;
                }
                .chatbot-send-btn:hover {
                    opacity: 0.9;
                }
                .chatbot-send-btn:disabled {
                    background: #9ca3af;
                    cursor: not-allowed;
                }
                .chatbot-send-btn svg {
                    width: 20px;
                    height: 20px;
                    fill: white;
                }

                .chatbot-powered {
                    text-align: center;
                    padding: 8px;
                    font-size: 11px;
                    color: #9ca3af;
                    background: white;
                }
                .chatbot-powered a {
                    color: #6b7280;
                    text-decoration: none;
                }

                @media (max-width: 480px) {
                    .chatbot-container {
                        width: calc(100% - 20px);
                        height: calc(100% - 100px);
                        left: 10px;
                        right: 10px;
                        bottom: 80px;
                        border-radius: 12px;
                    }
                    .chatbot-launcher {
                        width: 56px;
                        height: 56px;
                        ${this.config.position === 'bottom-left' ? 'left: 16px;' : 'right: 16px;'}
                        bottom: 16px;
                    }
                }
            `;

            const styleEl = document.createElement('style');
            styleEl.textContent = styles;
            document.head.appendChild(styleEl);
        },

        createWidget: function() {
            // Create launcher button
            const launcher = document.createElement('button');
            launcher.className = 'chatbot-launcher';
            launcher.innerHTML = `
                <svg class="chat-icon" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/></svg>
                <svg class="close-icon" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            `;
            this.elements.launcher = launcher;

            // Create chat container
            const container = document.createElement('div');
            container.className = 'chatbot-container chatbot-widget';
            container.innerHTML = `
                <div class="chatbot-header">
                    <div class="chatbot-header-avatar">
                        ${this.config.botAvatar ? `<img src="${this.config.botAvatar}" alt="">` : '🤖'}
                    </div>
                    <div class="chatbot-header-info">
                        <h3>${this.escapeHtml(this.config.botName)}</h3>
                        <p>Online | Typically replies instantly</p>
                    </div>
                </div>
                <div class="chatbot-messages"></div>
                <div class="chatbot-input-area">
                    <form class="chatbot-input-form">
                        <input type="text" class="chatbot-input" placeholder="Type your message..." autocomplete="off">
                        <button type="submit" class="chatbot-send-btn">
                            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                        </button>
                    </form>
                </div>
                <div class="chatbot-powered">Powered by <a href="#">Chatbot</a></div>
            `;
            this.elements.container = container;
            this.elements.messages = container.querySelector('.chatbot-messages');
            this.elements.form = container.querySelector('.chatbot-input-form');
            this.elements.input = container.querySelector('.chatbot-input');
            this.elements.sendBtn = container.querySelector('.chatbot-send-btn');

            document.body.appendChild(launcher);
            document.body.appendChild(container);
        },

        attachEventListeners: function() {
            this.elements.launcher.addEventListener('click', () => this.toggleWidget());
            this.elements.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.sendMessage();
            });
        },

        async initializeWidget() {
            try {
                const response = await this.apiCall('/api/v1/widget/init', {
                    method: 'POST',
                    body: JSON.stringify({ api_key: this.config.apiKey })
                });

                if (response.success) {
                    this.config.botName = response.config.name || this.config.botName;
                    this.config.welcomeMessage = response.config.welcome_message || this.config.welcomeMessage;
                    this.config.primaryColor = response.config.primary_color || this.config.primaryColor;

                    // Update header with new config
                    const headerName = this.elements.container.querySelector('.chatbot-header-info h3');
                    if (headerName) headerName.textContent = this.config.botName;
                }
            } catch (error) {
                console.error('[Chatbot] Failed to initialize:', error);
            }
        },

        toggleWidget: function() {
            this.state.isOpen = !this.state.isOpen;
            this.elements.container.classList.toggle('open', this.state.isOpen);
            this.elements.launcher.classList.toggle('open', this.state.isOpen);

            if (this.state.isOpen && !this.state.conversationId) {
                this.startConversation();
            }

            if (this.state.isOpen) {
                setTimeout(() => this.elements.input.focus(), 100);
            }
        },

        async startConversation() {
            try {
                const response = await this.apiCall('/api/v1/widget/conversation', {
                    method: 'POST',
                    body: JSON.stringify({
                        api_key: this.config.apiKey,
                        session_id: this.getSessionId()
                    })
                });

                this.state.conversationId = response.conversation_id;
                this.state.sessionId = response.session_id;

                // Add welcome message
                this.addMessage(this.config.welcomeMessage, 'bot');
            } catch (error) {
                console.error('[Chatbot] Failed to start conversation:', error);
                this.addMessage('Sorry, I\'m having trouble connecting. Please try again later.', 'bot');
            }
        },

        async sendMessage() {
            const message = this.elements.input.value.trim();
            if (!message || this.state.isLoading) return;

            this.elements.input.value = '';
            this.addMessage(message, 'user');
            this.setLoading(true);
            this.showTypingIndicator();

            try {
                const response = await this.apiCall('/api/v1/widget/message', {
                    method: 'POST',
                    body: JSON.stringify({
                        api_key: this.config.apiKey,
                        conversation_id: this.state.conversationId,
                        message: message
                    })
                });

                this.hideTypingIndicator();
                this.addMessage(response.response, 'bot');
            } catch (error) {
                console.error('[Chatbot] Failed to send message:', error);
                this.hideTypingIndicator();
                this.addMessage('Sorry, I couldn\'t process your message. Please try again.', 'bot');
            } finally {
                this.setLoading(false);
            }
        },

        addMessage: function(text, sender) {
            const messageEl = document.createElement('div');
            messageEl.className = `chatbot-message ${sender}`;
            messageEl.innerHTML = this.formatMessage(text);
            this.elements.messages.appendChild(messageEl);
            this.scrollToBottom();
            this.state.messages.push({ text, sender, timestamp: new Date() });
        },

        showTypingIndicator: function() {
            const typing = document.createElement('div');
            typing.className = 'chatbot-message typing';
            typing.id = 'chatbot-typing';
            typing.innerHTML = '<div class="chatbot-typing-indicator"><span></span><span></span><span></span></div>';
            this.elements.messages.appendChild(typing);
            this.scrollToBottom();
        },

        hideTypingIndicator: function() {
            const typing = document.getElementById('chatbot-typing');
            if (typing) typing.remove();
        },

        setLoading: function(loading) {
            this.state.isLoading = loading;
            this.elements.input.disabled = loading;
            this.elements.sendBtn.disabled = loading;
        },

        scrollToBottom: function() {
            this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
        },

        formatMessage: function(text) {
            // Convert markdown-like formatting
            let html = this.escapeHtml(text);

            // Bold
            html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

            // Italic
            html = html.replace(/\*(.*?)\*/g, '<em>$1</em>');

            // Line breaks
            html = html.replace(/\n/g, '<br>');

            // Links
            html = html.replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

            return html;
        },

        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        getSessionId: function() {
            let sessionId = localStorage.getItem('chatbot_session_id');
            if (!sessionId) {
                sessionId = 'sess_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
                localStorage.setItem('chatbot_session_id', sessionId);
            }
            return sessionId;
        },

        async apiCall(endpoint, options = {}) {
            const url = this.config.baseUrl + endpoint;
            const response = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...options.headers
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            return response.json();
        }
    };

    // Auto-initialize if script has data attributes
    const script = document.currentScript || document.querySelector('script[data-chatbot-key]');
    if (script) {
        const apiKey = script.getAttribute('data-chatbot-key');
        const baseUrl = script.getAttribute('data-chatbot-url');
        const position = script.getAttribute('data-chatbot-position');
        const color = script.getAttribute('data-chatbot-color');

        if (apiKey) {
            document.addEventListener('DOMContentLoaded', function() {
                ChatbotWidget.init({
                    apiKey: apiKey,
                    baseUrl: baseUrl,
                    position: position || 'bottom-right',
                    primaryColor: color || '#4F46E5'
                });
            });
        }
    }

    // Expose to global scope
    window.ChatbotWidget = ChatbotWidget;
})();
