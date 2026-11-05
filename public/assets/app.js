'use strict';

document.querySelectorAll('[data-absolute-url]').forEach(input => {
    input.value = new URL(input.value, window.location.href).href;
});
document.querySelectorAll('[data-copy]').forEach(button => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copy);
        const status = document.getElementById('copy-status');
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Link copiato. Conservalo in un posto sicuro.';
        } catch {
            input.focus();
            input.select();
            status.textContent = 'Seleziona e copia il link dal campo.';
        }
        setTimeout(() => { status.textContent = ''; }, 5000);
    });
});
document.getElementById('import-form')?.addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Recupero delle lezioni…';
    document.getElementById('form-status').textContent = 'Attendi senza chiudere la pagina. Nessuna credenziale verrà conservata.';
});
document.querySelectorAll('[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
