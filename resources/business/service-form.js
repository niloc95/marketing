/**
 * The service page (business/service_form.php). The page saves without this;
 * it only makes the form quicker to fill and keeps the summary card true:
 *
 * - Duration chips set the minutes box, and the box lights the matching chip.
 * - The deposit line shows the amount (rand, at least the smallest payment).
 * - Everyone / Nobody tick the people, and a warning shows when nobody gives it.
 * - The summary card follows the name, category, time, price, modes and people.
 *
 * Copy here matches the view's server rendered first state.
 */

function durationText(minutes) {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h === 0) return `${m} min`;
    return m > 0 ? `${h} h ${m} min` : `${h} h`;
}

// The view's price rule: spaces dropped, a comma is a decimal point.
function priceCents(raw) {
    const text = String(raw).trim().replace(/ /g, '').replace(/,/g, '.');
    if (text === '' || !/^\d+(\.\d+)?$/.test(text)) return null;
    return Math.round(parseFloat(text) * 100);
}

function moneyText(symbol, cents) {
    const value = cents / 100;
    return symbol + (Number.isInteger(value) ? String(value) : value.toFixed(2));
}

function paint(dot, color) {
    if (/^#[0-9a-f]{6}$/i.test(color || '')) {
        dot.style.backgroundColor = color;
    }
}

export function initServiceForm(root) {
    const form = root.querySelector('#svc-form');
    if (!form) return undefined;

    const $ = (selector) => root.querySelector(selector);
    const symbol = root.dataset.symbol || '';
    const deposits = root.dataset.deposits === '1';
    const minDeposit = parseInt(root.dataset.minDeposit || '500', 10);

    const name = $('#svc-name');
    const duration = $('#svc-duration');
    const price = $('#svc-price');
    const deposit = $('#svc-deposit');
    const category = $('#svc-cat');
    const chips = [...root.querySelectorAll('[data-duration]')];
    const people = [...form.querySelectorAll('input[name="provider_ids[]"]')];
    const modes = [...form.querySelectorAll('input[name="delivery_modes[]"]')];

    function update() {
        const minutes = parseInt(duration.value, 10);
        const valid = Number.isFinite(minutes) && minutes > 0;
        chips.forEach((chip) => chip.setAttribute('aria-pressed', String(valid && parseInt(chip.dataset.duration, 10) === minutes)));
        const durationLabel = valid ? durationText(minutes) : '';

        $('[data-sum-name]').textContent = name.value.trim() || 'Your service';
        $('[data-sum-duration]').textContent = durationLabel || 'No duration yet';

        const cents = priceCents(price.value);
        $('[data-sum-price]').textContent = cents === null ? 'No price shown' : moneyText(symbol, cents);

        if (deposits && deposit) {
            const pct = Math.min(100, Math.max(0, parseInt(deposit.value, 10) || 0));
            const amount = pct > 0 && cents !== null ? Math.round((cents * pct) / 100) : null;
            let text;
            if (pct <= 0) text = 'No deposit. Set a percentage to ask for one.';
            else if (cents === null) text = 'A deposit needs a price.';
            else if (amount < minDeposit) text = `Below the ${moneyText(symbol, minDeposit)} smallest payment, so no deposit is asked.`;
            else text = `${moneyText(symbol, amount)} of ${moneyText(symbol, cents)}.`;
            $('[data-deposit-text]').textContent = text;
            const row = $('[data-sum-deposit-row]');
            const shown = amount !== null && amount >= minDeposit;
            row.hidden = !shown;
            if (shown) $('[data-sum-deposit]').textContent = `${moneyText(symbol, amount)} deposit`;
        }

        if (category) {
            const option = category.selectedOptions[0];
            const box = $('[data-sum-cat]');
            box.hidden = !category.value;
            if (category.value && option) {
                $('[data-sum-cat-name]').textContent = option.textContent;
                paint(box.querySelector('.biz-dot'), option.dataset.color);
            }
        }

        const chosenModes = modes.filter((box) => box.checked).map((box) => box.dataset.modeLabel);
        $('[data-sum-modes]').textContent = chosenModes.length ? chosenModes.join(', ') : 'Not chosen yet';

        const chosen = people.filter((box) => box.checked).map((box) => box.dataset.person);
        const who = $('[data-sum-people]');
        who.textContent = chosen.length ? `With ${chosen.join(', ')}` : 'Nobody yet';
        who.classList.toggle('text-amber-700', chosen.length === 0);
        who.classList.toggle('dark:text-amber-400', chosen.length === 0);
        const nobody = $('[data-nobody]');
        if (nobody) nobody.hidden = chosen.length > 0;
    }

    function onClick(event) {
        const chip = event.target.closest('[data-duration]');
        if (chip) {
            duration.value = chip.dataset.duration;
            update();
            return;
        }
        const pick = event.target.closest('[data-pick]');
        if (pick) {
            people.forEach((box) => { box.checked = pick.dataset.pick === 'all'; });
            update();
        }
    }

    const pickAll = $('[data-pick-all]');
    if (pickAll) pickAll.hidden = false;

    root.addEventListener('input', update);
    root.addEventListener('change', update);
    root.addEventListener('click', onClick);
    update();

    return () => {
        root.removeEventListener('input', update);
        root.removeEventListener('change', update);
        root.removeEventListener('click', onClick);
    };
}
