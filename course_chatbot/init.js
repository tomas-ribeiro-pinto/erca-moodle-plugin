/**
 * Creates and displays the chat modal.
 */
function showChatModal() {
    // Get the modal
    var modal = document.getElementById("chatModal");
    
    if (modal.style.display === "block") {
        modal.style.display = "none";
    } else {
        modal.style.display = "block";
    }
}

var chatbot_id = document.querySelector('input[name="chatbot_id"]').value || '1'; // Default to 1 if not set
var user_email = document.querySelector('input[name="user_email"]').value || '';
var user_name = document.querySelector('input[name="user_name"]').value || '';
const form = document.getElementById('messageForm');
const input = document.getElementById('messageInput');
const chatContainer = document.getElementById('chat-container');
const sendBtn = document.getElementById('sendBtn');

// Load chat history on page load
async function loadChatHistory() {
    try {
        const response = await fetch(`/local/course_chatbot/chatbot_ajax_handler.php?action=history&chatbot_id=${chatbot_id}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                user_email: user_email
            })
        });
        const data = await response.json();
        
        if (response.ok && data.history) {
            data.history.forEach(message => {
                addMessageToChat(message.content, message.role);
            });
        }
    } catch (error) {
        console.error('Failed to load chat history:', error);
    }
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Add message to chat display
function addMessageToChat(content, role) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `chat-message ${role === 'user' ? 'user-message' : 'bot-message'}`;
    messageDiv.textContent = content;
    chatContainer.appendChild(messageDiv);
    chatContainer.scrollTop = chatContainer.scrollHeight;
    return messageDiv;
}

// Add streaming message container
function addStreamingMessage() {
    const messageDiv = document.createElement('div');
    messageDiv.className = 'chat-message streaming-message';
    messageDiv.id = 'streaming-message';
    
    // Create a container for the typing indicators with some text
    const typingContainer = document.createElement('div');
    typingContainer.style.display = 'flex';
    typingContainer.style.alignItems = 'center';
    typingContainer.style.gap = '8px';
    typingContainer.style.padding = '8px 0';
    
    
    const indicatorsContainer = document.createElement('span');
    indicatorsContainer.style.display = 'inline-flex';
    indicatorsContainer.style.alignItems = 'center';
    indicatorsContainer.innerHTML = '<span class="typing-indicator"></span><span class="typing-indicator"></span><span class="typing-indicator"></span>';
    
    typingContainer.appendChild(indicatorsContainer);
    messageDiv.appendChild(typingContainer);
    
    chatContainer.appendChild(messageDiv);
    chatContainer.scrollTop = chatContainer.scrollHeight;
    
    // Fallback: If CSS animation doesn't work, manually animate the dots
    startManualTypingAnimation(indicatorsContainer);
    
    return messageDiv;
}

// Fallback manual animation in case CSS animations are blocked
function startManualTypingAnimation(container) {
    const dots = container.querySelectorAll('.typing-indicator');
    let currentDot = 0;
    
    const animationInterval = setInterval(() => {
        // Reset all dots
        dots.forEach(dot => {
            dot.style.opacity = '0.3';
            dot.style.transform = 'scale(1)';
        });
        
        // Highlight current dot
        if (dots[currentDot]) {
            dots[currentDot].style.opacity = '1';
            dots[currentDot].style.transform = 'scale(1.3)';
        }
        
        currentDot = (currentDot + 1) % dots.length;
    }, 500);
    
    // Store interval ID to clear it later
    container.animationInterval = animationInterval;
}

// Update streaming message content
function updateStreamingMessage(content) {
    const streamingDiv = document.getElementById('streaming-message');
    if (streamingDiv) {
        streamingDiv.textContent = content;
    }
}

// Finalize streaming message
function finalizeStreamingMessage() {
    const streamingDiv = document.getElementById('streaming-message');
    if (streamingDiv) {
        // Clear manual animation if it exists
        const indicatorsContainer = streamingDiv.querySelector('span[style*="inline-flex"]');
        if (indicatorsContainer && indicatorsContainer.animationInterval) {
            clearInterval(indicatorsContainer.animationInterval);
        }
        
        streamingDiv.className = 'chat-message bot-message';
        streamingDiv.id = '';
    }
}

// Show error message
function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'chat-message error';
    errorDiv.textContent = `Error: ${message}`;
    chatContainer.appendChild(errorDiv);
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Stream response using POST with Server-Sent Events
async function streamResponse(userMessage) {
    return new Promise(async (resolve, reject) => {
        // Create streaming message container
        const streamingDiv = addStreamingMessage();
        let accumulatedResponse = '';

        try {
            const response = await fetch(`/local/course_chatbot/chatbot_ajax_handler.php?action=prompt&chatbot_id=${chatbot_id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                },
                body: JSON.stringify({
                    user_email: user_email,
                    prompt: userMessage
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            while (true) {
                const { done, value } = await reader.read();
                
                if (done) {
                    finalizeStreamingMessage();
                    resolve(accumulatedResponse);
                    break;
                }

                const chunk = decoder.decode(value, { stream: true });
                const lines = chunk.split('\n');

                for (const line of lines) {
                    if (line.trim() && line.startsWith('data: ')) {
                        try {
                            const jsonData = line.substring(6); // Remove 'data: ' prefix
                            const data = JSON.parse(jsonData);
                            
                            if (data.chunk) {
                                accumulatedResponse += data.chunk;
                                updateStreamingMessage(accumulatedResponse);
                            } else if (data.done) {
                                finalizeStreamingMessage();
                                resolve(accumulatedResponse);
                                return;
                            } else if (data.error) {
                                streamingDiv.remove();
                                showError(data.error);
                                reject(new Error(data.error));
                                return;
                            }
                        } catch (parseError) {
                            console.warn('Failed to parse SSE line:', line);
                        }
                    }
                }
            }
        } catch (error) {
            streamingDiv.remove();
            showError(error.message);
            reject(error);
        }
    });
}

form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const userMessage = input.value.trim();
    
    if (userMessage) {
        // Add user message to chat
        addMessageToChat(userMessage, 'user');
        
        // Disable form while processing
        sendBtn.disabled = true;
        input.disabled = true;
        input.value = "";
        
        try {
            // Use SSE streaming
            await streamResponse(userMessage);
        } catch (error) {
            console.error('Streaming failed:', error);
        } finally {
            // Re-enable form
            sendBtn.disabled = false;
            input.disabled = false;
            input.focus();
        }
    }
});

// Load chat history when page loads
window.addEventListener('load', loadChatHistory);