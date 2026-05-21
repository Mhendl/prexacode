/* PREXAcode - Main JS */

// ── Generar session IDs únicos por instancia de chat ──
function generateSessionId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'sess-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
}

const floatSessionId  = generateSessionId();
const inlineSessionId = generateSessionId();

// ── Navbar scroll effect ──
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
  navbar.classList.toggle('scrolled', window.scrollY > 40);
});

// ── Mobile nav ──
const hamburger    = document.getElementById('hamburger');
const mobileNav    = document.getElementById('mobileNav');
const mobileNavClose = document.getElementById('mobileNavClose');

hamburger?.addEventListener('click', () => mobileNav.classList.add('open'));
mobileNavClose?.addEventListener('click', () => mobileNav.classList.remove('open'));
mobileNav?.querySelectorAll('a').forEach(a => {
  a.addEventListener('click', () => mobileNav.classList.remove('open'));
});

// ══════════════════════════════════════════════════════
// CHAT ENGINE — lógica reutilizable para ambos chats
// ══════════════════════════════════════════════════════
function createChatEngine({ messagesEl, inputEl, sendBtn, sessionId, onFirstMessage }) {
  const history = [];
  let sending   = false;
  let ticketFormShown = false;

  function addMsg(text, type) {
    const el = document.createElement('div');
    el.className = `msg-bubble ${type}`;
    el.textContent = text;
    messagesEl.appendChild(el);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return el;
  }

  function addBotMsg(text) {
    const typing = document.createElement('div');
    typing.className = 'typing-indicator';
    typing.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
    messagesEl.appendChild(typing);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    return new Promise(resolve => {
      setTimeout(() => {
        typing.remove();
        addMsg(text, 'bot');
        resolve();
      }, 800);
    });
  }

  function buildResumen() {
    return history
      .filter(m => m.role !== 'system')
      .slice(-6)
      .map(m => (m.role === 'user' ? 'Cliente: ' : 'Asistente: ') + m.content)
      .join('\n');
  }

  function showTicketForm() {
    if (ticketFormShown) return;
    ticketFormShown = true;

    const wrapper = document.createElement('div');
    wrapper.className = 'ticket-form-wrapper';
    wrapper.innerHTML = `
      <div class="ticket-form">
        <p class="ticket-form-title">📋 Dejá tus datos y te contactamos</p>
        <input type="text"  class="tf-input" data-tf="nombre"   placeholder="Tu nombre *" />
        <input type="email" class="tf-input" data-tf="email"    placeholder="Tu email *" />
        <input type="tel"   class="tf-input" data-tf="telefono" placeholder="WhatsApp (opcional)" />
        <button class="tf-submit" data-tf="btn">Enviar solicitud</button>
        <p class="tf-note">Te contactamos a la brevedad.</p>
      </div>
    `;
    messagesEl.appendChild(wrapper);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    const btn      = wrapper.querySelector('[data-tf="btn"]');
    const nInput   = wrapper.querySelector('[data-tf="nombre"]');
    const eInput   = wrapper.querySelector('[data-tf="email"]');
    const tInput   = wrapper.querySelector('[data-tf="telefono"]');
    const noteEl   = wrapper.querySelector('.tf-note');

    btn?.addEventListener('click', submitTicket);
    [nInput, eInput, tInput].forEach(inp => {
      inp?.addEventListener('keydown', ev => { if (ev.key === 'Enter') submitTicket(); });
    });
    nInput?.focus();

    async function submitTicket() {
      const nombre   = nInput?.value.trim();
      const email    = eInput?.value.trim();
      const telefono = tInput?.value.trim();

      if (!nombre || !email) {
        if (noteEl) { noteEl.textContent = '⚠️ Nombre y email son obligatorios.'; noteEl.style.color = '#f87171'; }
        return;
      }

      btn.disabled    = true;
      btn.textContent = 'Enviando...';

      const payload = {
        nombre,
        email,
        telefono,
        resumen: buildResumen(),
        conversacion: history.filter(m => m.role !== 'system'),
        session_id: sessionId
      };

      try {
        const res  = await fetch('/api/ticket.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success) {
          wrapper.innerHTML = `<div class="ticket-success">✅ ${data.message}</div>`;
          history.push({ role: 'assistant', content: `Ticket #${data.ticket_id} generado para ${nombre}.` });
          if (inputEl) inputEl.placeholder = 'Solicitud enviada. ¡Gracias!';
        } else {
          if (noteEl) { noteEl.textContent = '❌ ' + (data.error || 'Error al enviar.'); noteEl.style.color = '#f87171'; }
          btn.disabled    = false;
          btn.textContent = 'Enviar solicitud';
        }
      } catch {
        if (noteEl) { noteEl.textContent = '❌ No se pudo enviar. Intentá de nuevo.'; noteEl.style.color = '#f87171'; }
        btn.disabled    = false;
        btn.textContent = 'Enviar solicitud';
      }
    }
  }

  async function send(text) {
    if (!text || sending) return;
    if (onFirstMessage) { onFirstMessage(); }

    inputEl.value   = '';
    sendBtn.disabled = true;
    sending          = true;
    addMsg(text, 'user');
    history.push({ role: 'user', content: text });

    const typing = document.createElement('div');
    typing.className = 'typing-indicator';
    typing.innerHTML = '<div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div>';
    messagesEl.appendChild(typing);
    messagesEl.scrollTop = messagesEl.scrollHeight;

    try {
      const res  = await fetch('/api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: history, session_id: sessionId })
      });
      const data = await res.json();
      typing.remove();

      if (data.message) {
        const triggerForm = data.message.includes('[[FORM]]');
        const cleanMsg    = data.message.replace(/\[\[FORM\]\]/g, '').trimEnd();

        addMsg(cleanMsg, 'bot');
        history.push({ role: 'assistant', content: cleanMsg });

        if (triggerForm && !ticketFormShown) {
          setTimeout(() => showTicketForm(), 600);
        }
      } else {
        addMsg('Lo siento, hubo un error. Por favor intentá nuevamente.', 'bot');
      }
    } catch {
      typing.remove();
      addMsg('No pude conectarme. Por favor intentá de nuevo.', 'bot');
    }

    sending          = false;
    sendBtn.disabled = false;
    inputEl?.focus();
  }

  function init(greeting) {
    setTimeout(() => addBotMsg(greeting), 400);
  }

  sendBtn?.addEventListener('click', () => send(inputEl?.value.trim()));
  inputEl?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send(inputEl?.value.trim());
    }
  });

  return { send, init };
}

// ══════════════════════════════════════════════════════
// CHAT FLOTANTE
// ══════════════════════════════════════════════════════
const chatToggle   = document.getElementById('chatToggle');
const chatPanel    = document.getElementById('chatPanel');
const chatMessages = document.getElementById('chatMessages');
const chatInput    = document.getElementById('chatInput');
const chatSend     = document.getElementById('chatSend');
const chatBubble   = document.getElementById('chatBubble');

let chatOpen       = false;
let bubbleHidden   = false;
let floatInited    = false;

const floatEngine = createChatEngine({
  messagesEl: chatMessages,
  inputEl:    chatInput,
  sendBtn:    chatSend,
  sessionId:  floatSessionId
});

chatToggle?.addEventListener('click', () => {
  chatOpen = !chatOpen;
  chatPanel.classList.toggle('open', chatOpen);
  chatToggle.classList.toggle('active', chatOpen);

  if (chatOpen) {
    chatBubble?.classList.add('hidden');
    bubbleHidden = true;
    chatInput?.focus();
    if (!floatInited) {
      floatInited = true;
      floatEngine.init('¡Hola! Soy el asistente de PREXAcode 🤖\n\n¿En qué puedo ayudarte? Podés preguntarme sobre nuestros servicios de software a medida o agentes de IA.');
    }
  }
});

// Burbuja de atención
setTimeout(() => {
  if (!bubbleHidden && chatBubble) {
    chatBubble.classList.remove('hidden');
    setTimeout(() => { if (!bubbleHidden) chatBubble?.classList.add('hidden'); }, 6000);
  }
}, 3000);

// ══════════════════════════════════════════════════════
// CHAT INLINE (sección contacto)
// ══════════════════════════════════════════════════════
const inlineMsgs  = document.getElementById('inlineChatMessages');
const inlineInput = document.getElementById('inlineChatInput');
const inlineSend  = document.getElementById('inlineChatSend');

let inlineInited = false;

function initInlineChat() {
  if (inlineInited || !inlineMsgs) return;
  inlineInited = true;
}

const inlineEngine = createChatEngine({
  messagesEl: inlineMsgs,
  inputEl:    inlineInput,
  sendBtn:    inlineSend,
  sessionId:  inlineSessionId,
  onFirstMessage: initInlineChat
});

// Iniciar inline chat con saludo
if (inlineMsgs) {
  setTimeout(() => {
    inlineEngine.init('¡Hola! Soy el asistente de PREXAcode 🤖\n\n¿En qué puedo ayudarte hoy? Podés consultarme sobre nuestros servicios de software a medida, agentes de IA o automatización.');
    inlineInited = true;
  }, 500);
}

// ── Quick link buttons (atajos de consulta) ──
document.querySelectorAll('.quick-link-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const msg = btn.getAttribute('data-msg');
    if (!msg || !inlineInput) return;

    // Scroll al chat inline
    const chatSection = document.getElementById('contacto');
    if (chatSection) {
      const top = chatSection.getBoundingClientRect().top + window.scrollY - 80;
      window.scrollTo({ top, behavior: 'smooth' });
    }

    // Enviar mensaje al chat inline después del scroll
    setTimeout(() => {
      inlineEngine.send(msg);
    }, 600);
  });
});

// ── Smooth scroll ──
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const target = document.querySelector(a.getAttribute('href'));
    if (target) {
      e.preventDefault();
      window.scrollTo({ top: target.getBoundingClientRect().top + window.scrollY - 80, behavior: 'smooth' });
    }
  });
});

// ── Entrance animations ──
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.style.opacity   = '1';
      entry.target.style.transform = 'translateY(0)';
      observer.unobserve(entry.target);
    }
  });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

document.querySelectorAll('.service-card, .process-step, .benefit-item').forEach(el => {
  el.style.opacity   = '0';
  el.style.transform = 'translateY(24px)';
  el.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
  observer.observe(el);
});
