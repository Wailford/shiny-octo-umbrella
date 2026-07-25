const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const path = require('path');

const app = express();
const server = http.createServer(app);
const io = new Server(server);

// Serve static files from the 'public' directory
app.use(express.static(path.join(__dirname, 'public')));

// Handle Socket.io connections
io.on('connection', (socket) => {
  console.log('A user connected:', socket.id);

  // Broadcast when a user joins
  socket.broadcast.emit('message', {
    user: 'System',
    text: 'A new user has joined the chat.',
    time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  });

  // Listen for chat messages
  socket.on('chatMessage', (msg) => {
    io.emit('message', {
      user: msg.user,
      text: msg.text,
      time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    });
  });

  // Handle disconnect
  socket.on('disconnect', () => {
    console.log('User disconnected:', socket.id);
    io.emit('message', {
      user: 'System',
      text: 'A user has left the chat.',
      time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    });
  });
});

const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
  console.log(`Server is running on http://localhost:${PORT}`);
});
