const socket = io();

const chatForm = document.getElementById('chat-form');
const chatMessages = document.getElementById('chat-messages');
const usernameInput = document.getElementById('username');
const msgInput = document.getElementById('msg');

// Get message from server
socket.on('message', (message) => {
  outputMessage(message);

  // Scroll down to the newest message
  chatMessages.scrollTop = chatMessages.scrollHeight;
});

// Message submit
chatForm.addEventListener('submit', (e) => {
  e.preventDefault();

  // Get message text and username
  let msg = msgInput.value.trim();
  let username = usernameInput.value.trim();

  if (!msg || !username) {
    return false;
  }

  // Emit message to server
  socket.emit('chatMessage', { text: msg, user: username });

  // Clear input
  msgInput.value = '';
  msgInput.focus();
});

// Output message to DOM
function outputMessage(message) {
  const div = document.createElement('div');
  
  // Determine message type (system, self, or other)
  if (message.user === 'System') {
    div.classList.add('message', 'system');
    div.innerHTML = `<p class="text">${message.text}</p>`;
  } else {
    // Check if it's the current user's message
    const currentUser = usernameInput.value.trim();
    if (message.user === currentUser && currentUser !== '') {
      div.classList.add('message', 'self');
    } else {
      div.classList.add('message', 'other');
    }
    
    div.innerHTML = `
      <p class="meta"><span>${message.user}</span> ${message.time}</p>
      <p class="text">${message.text}</p>
    `;
  }
  
  document.querySelector('.chat-messages').appendChild(div);
}
