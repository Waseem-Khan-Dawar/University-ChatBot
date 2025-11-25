
function sendMessage() {
    const message = document.getElementById("message").value.trim();
    if (!message) return;
    appendMessage("user", message);
    document.getElementById("message").value = "";

    // Typing indicator
    const typingId = Date.now();
    appendMessage("bot", "💬 Bot is typing...", typingId);

    fetch("/chat", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ message: message })
    })
    .then(res => res.json())
    .then(data => {
        updateMessage(typingId, data.reply);
    })
    .catch(() => {
        updateMessage(typingId, "⚠️ Error connecting to server.");
    });
}

function appendMessage(sender, text, id = null) {
    const chatbox = document.getElementById("chatbox");
    const msg = document.createElement("div");
    msg.className = `message ${sender}`;
    msg.innerText = text;
    msg.dataset.id = id;
    chatbox.appendChild(msg);
    chatbox.scrollTop = chatbox.scrollHeight;
}

function updateMessage(id, newText) {
    const chatbox = document.getElementById("chatbox");
    const msg = [...chatbox.children].find(m => m.dataset.id == id);
    if (msg) msg.innerText = newText;
}
