(function () {
  'use strict';

  document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    var input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    button.addEventListener('click', function () {
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      var showIcon = button.querySelector('[data-password-show-icon]');
      var hideIcon = button.querySelector('[data-password-hide-icon]');
      if (showIcon) showIcon.hidden = reveal;
      if (hideIcon) hideIcon.hidden = !reveal;
      button.setAttribute('aria-label', reveal ? 'Ocultar senha' : 'Ver senha');
    });
  });

  document.querySelectorAll('[data-password-strength]').forEach(function (input) {
    var field = input.closest('.password-field');
    var progress = field ? field.querySelector('progress') : null;
    var label = field ? field.querySelector('[data-password-strength-label]') : null;
    var labels = ['Muito fraca', 'Fraca', 'Regular', 'Boa', 'Quase lá', 'Senha forte'];
    var update = function () {
      var value = input.value;
      var score = 0;
      if (value.length >= 12 && value.length <= 128) score += 1;
      if (/\p{Ll}/u.test(value)) score += 1;
      if (/\p{Lu}/u.test(value)) score += 1;
      if (/\p{N}/u.test(value)) score += 1;
      if (/[^\p{L}\p{N}]/u.test(value)) score += 1;
      if (progress) progress.value = score;
      if (label) label.textContent = value === ''
        ? 'Use 12 caracteres, maiúscula, minúscula, número e símbolo.'
        : labels[score];
    };
    input.addEventListener('input', update);
    update();
  });

  document.querySelectorAll('[data-password-confirmation]').forEach(function (confirmation) {
    var original = document.getElementById(confirmation.getAttribute('data-password-confirmation'));
    if (!original) return;
    var validate = function () {
      confirmation.setCustomValidity(confirmation.value !== original.value ? 'As senhas não coincidem.' : '');
    };
    original.addEventListener('input', validate);
    confirmation.addEventListener('input', validate);
  });

  document.querySelectorAll('[data-reset-cooldown]').forEach(function (button) {
    var remaining = Number.parseInt(button.getAttribute('data-reset-cooldown'), 10) || 0;
    if (remaining <= 0) return;
    button.disabled = true;
    var timer = window.setInterval(function () {
      remaining -= 1;
      if (remaining <= 0) {
        window.clearInterval(timer);
        button.disabled = false;
        button.textContent = 'Enviar link de redefinição';
        return;
      }
      button.textContent = 'Aguarde ' + remaining + 's';
    }, 1000);
  });
}());
