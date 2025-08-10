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
var user_email = document.querySelector('input[name="user_email"]').value || '1'; // Default user ID, can be replaced with actual user ID from session
const form = document.getElementById('messageForm');
const input = document.getElementById('messageInput');
const chatContainer = document.getElementById('chat-container');
const sendBtn = document.getElementById('sendBtn');

// Load chat history on page load
async function loadChatHistory() {
    try {
        const response = await fetch(`/local/course_chatbot/chatbot_ajax_handler.php?action=history&chatbot_id=${chatbot_id}&user_email=${user_email}`);
        const data = await response.json();
        
        if (response.ok && data.history) {
            data.history.forEach(message => {
                addMessageToChat(message.content, message.role);
            });
        }
    } catch (error) {
        console.error('Failed to load chat history:', error);
    }
}

// Add message to chat display
function addMessageToChat(content, role) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `chat-message ${role === 'user' ? 'user-message' : 'bot-message'}`;
    messageDiv.textContent = content;
    chatContainer.appendChild(messageDiv);
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Show loading message
function showLoading() {
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'chat-message loading';
    loadingDiv.textContent = 'Thinking...';
    loadingDiv.id = 'loading-message';
    chatContainer.appendChild(loadingDiv);
    chatContainer.scrollTop = chatContainer.scrollHeight;
}

// Remove loading message
function removeLoading() {
    const loadingDiv = document.getElementById('loading-message');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

form.addEventListener('submit', async function(e) {
    e.preventDefault();
    const userMessage = input.value.trim();
    
    if (userMessage) {
        // Add user message to chat
        addMessageToChat(userMessage, 'user');
        
        // Show loading state
        showLoading();
        
        // Disable form while processing
        sendBtn.disabled = true;
        input.disabled = true;
        input.value = "";
        
        try {
            const response = await fetch(`/local/course_chatbot/chatbot_ajax_handler.php?action=prompt&chatbot_id=${chatbot_id}&user_email=${user_email}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    prompt: userMessage
                })
            });
            
            const data = await response.json();
            
            removeLoading();
            
            if (response.ok) {
                addMessageToChat(data.response, 'assistant');
            } else {
                const errorDiv = document.createElement('div');
                errorDiv.className = 'chat-message error';
                errorDiv.textContent = `Error: ${data.error}`;
                chatContainer.appendChild(errorDiv);
                chatContainer.scrollTop = chatContainer.scrollHeight;
            }
        } catch (error) {
            removeLoading();
            const errorDiv = document.createElement('div');
            errorDiv.className = 'chat-message error';
            errorDiv.textContent = `Network error: ${error.message}`;
            chatContainer.appendChild(errorDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
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