(() => {
  'use strict';
  const rows = document.getElementById('convertino-button-rows');
  if (!rows) return;
  document.getElementById('convertino-add-button').addEventListener('click', () => {
    if (rows.children.length >= 20) return;
    rows.append(document.getElementById('convertino-button-template').content.cloneNode(true));
    rows.lastElementChild.querySelector('input').focus();
  });
  rows.addEventListener('click', e => {
    if (e.target.closest('.convertino-remove-button')) e.target.closest('.convertino-button-row').remove();
  });
  const validate = input => {
    let message = '';
    if (input.value.trim()) {
      try { document.querySelector(input.value); } catch { message = 'Enter a valid CSS selector, such as #get-started or .buy-button.'; }
    }
    input.setCustomValidity(message);
    input.closest('.convertino-button-row').querySelector('.convertino-selector-error').textContent = message;
  };
  rows.addEventListener('input', e => { if (e.target.name === 'button_selector[]') validate(e.target); });
})();
