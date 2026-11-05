/**
 * Settings, Messages: show the fields of the SMS or WhatsApp provider
 * chosen (business/_settings_notifications.php). Without the script every
 * provider's fields stay as the server rendered them, which still saves.
 */
export function initProviderForms() {
    document.querySelectorAll('[data-provider-form]').forEach((form) => {
        const select = form.querySelector('[data-provider-select]');
        if (!select) {
            return;
        }
        const show = () => {
            form.querySelectorAll('[data-provider]').forEach((fieldset) => {
                fieldset.hidden = fieldset.dataset.provider !== select.value;
            });
        };
        select.addEventListener('change', show);
        show();
    });
}
