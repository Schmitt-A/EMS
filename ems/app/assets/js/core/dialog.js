// 7.6 Natives <dialog> mit showModal(): Fokusfalle, Esc, Fokus zurück zum Auslöser.
// Mobil (unter 640 px) ein Bottom Sheet, das man am Griff nach unten wegwischt.
import { $, $$, reducedMotion } from './dom.js';

const triggers = new WeakMap();

export function openDialog(dialog, trigger = document.activeElement) {
  if (!dialog || dialog.open) return;
  triggers.set(dialog, trigger);
  dialog.removeAttribute('data-closing');
  dialog.showModal();
  dialog.dispatchEvent(new CustomEvent('ems:open'));
}

export function closeDialog(dialog) {
  if (!dialog?.open || dialog.hasAttribute('data-closing')) return;
  const finish = () => {
    dialog.removeAttribute('data-closing');
    dialog.style.removeProperty('transform');
    dialog.close();
    const trigger = triggers.get(dialog);
    if (trigger && document.contains(trigger)) trigger.focus({ preventScroll: true });
    if (dialog.dataset.return) history.replaceState(null, '', dialog.dataset.return);
  };
  if (reducedMotion()) {
    finish();
    return;
  }
  dialog.setAttribute('data-closing', '');
  dialog.addEventListener('animationend', finish, { once: true });
  setTimeout(() => dialog.open && dialog.hasAttribute('data-closing') && finish(), 400);
}

function bindSwipe(dialog) {
  let start = null;
  const sheet = () => matchMedia('(max-width: 39.99rem)').matches;
  for (const grip of $$('[data-grip]', dialog)) {
    grip.addEventListener('pointerdown', (event) => {
      if (!sheet() || event.target.closest('button')) return;
      start = { y: event.clientY, id: event.pointerId };
      grip.setPointerCapture(event.pointerId);
    });
    grip.addEventListener('pointermove', (event) => {
      if (!start || event.pointerId !== start.id) return;
      const dy = Math.max(0, event.clientY - start.y);
      dialog.style.transform = `translateY(${dy}px)`;
    });
    const end = (event) => {
      if (!start || event.pointerId !== start.id) return;
      const dy = event.clientY - start.y;
      start = null;
      if (dy > 80) {
        dialog.style.transform = '';
        closeDialog(dialog);
      } else {
        dialog.style.removeProperty('transform');
      }
    };
    grip.addEventListener('pointerup', end);
    grip.addEventListener('pointercancel', end);
  }
}

export function initDialogs(root = document) {
  for (const dialog of $$('dialog.dialog', root)) {
    if (dialog.dataset.bound) continue;
    dialog.dataset.bound = '1';
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      closeDialog(dialog);
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) closeDialog(dialog);
      if (event.target.closest('[data-close-dialog]')) closeDialog(dialog);
    });
    bindSwipe(dialog);
    if (dialog.hasAttribute('data-autoopen')) openDialog(dialog, null);
  }
}

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-open-dialog]');
  if (!trigger || trigger.hasAttribute('data-dragged')) return;
  const dialog = document.getElementById(trigger.dataset.openDialog);
  if (!dialog) return;
  event.preventDefault();
  openDialog(dialog, trigger);
});

export const dialogOf = (node) => node.closest('dialog') || $('dialog[open]');
