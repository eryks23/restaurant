/**
 * assets/js/payment.js
 * Poprawiony klient wysyłający FormData -> /api/create-payment.php
 */

document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('bookingForm');
  const payBtn = document.getElementById('payBtn');

  function setBtnLoading(loading, text) {
    if (!payBtn) return;
    payBtn.disabled = loading;
    payBtn.textContent = loading ? (text || 'Przekierowuję...') : 'Przejdź do płatności';
    if (loading) payBtn.setAttribute('aria-busy', 'true'); else payBtn.removeAttribute('aria-busy');
  }

  async function fetchWithTimeout(url, options = {}, timeoutMs = 15000) {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const resp = await fetch(url, { ...options, signal: controller.signal });
      clearTimeout(id);
      return resp;
    } catch (err) {
      clearTimeout(id);
      throw err;
    }
  }

  if (!form) return;

  form.addEventListener('submit', async function (e) {
    e.preventDefault();

    // Basic client-side validation (adjust selectors to your markup)
    const slotIdField = document.getElementById('slot_id');
    const timeInput = document.getElementById('timePicker');
    const dateInput = document.getElementById('datePicker');
    const gdprCheckbox = document.getElementById('gdpr');
    const finalAmountField = document.getElementById('final_amount_grosze');
    const participantsInput = document.getElementById('participants');
    const durationSelect = document.getElementById('duration');
    const appliedVoucherField = document.getElementById('applied_voucher');

    const hasSlot = slotIdField && slotIdField.value;
    const hasTime = timeInput && timeInput.value;
    if (!hasSlot && !hasTime) { alert('Wybierz godzinę z listy lub ustaw czas ręcznie.'); return; }
    if (!gdprCheckbox || !gdprCheckbox.checked) { alert('Musisz zaakceptować regulamin / RODO.'); return; }

    setBtnLoading(true, 'Wysyłam dane...');

    // Build slot_id if needed
    let slotIdValue = slotIdField && slotIdField.value ? slotIdField.value : '';
    if (!slotIdValue && dateInput && timeInput && dateInput.value && timeInput.value) {
      slotIdValue = `${dateInput.value}T${timeInput.value}`;
    }

    const payload = {
      firstName: document.getElementById('firstName')?.value?.trim() || '',
      lastName: document.getElementById('lastName')?.value?.trim() || '',
      email: document.getElementById('email')?.value?.trim() || '',
      phone: document.getElementById('phone')?.value?.trim() || '',
      duration: durationSelect?.value || '',
      slot_id: slotIdValue,
      date: dateInput?.value || '',
      time: timeInput?.value || '',
      gdpr: gdprCheckbox && gdprCheckbox.checked ? '1' : '0',
      participants: String(Number(participantsInput?.value || 1)),
      final_amount_grosze: String(Number(finalAmountField?.value || 0)),
      applied_voucher: appliedVoucherField?.value || ''
    };

    console.groupCollapsed('PAYMENT DEBUG: payload');
    console.log('Endpoint (form.action):', form.getAttribute('action'));
    console.log(payload);
    console.groupEnd();

    // Prefer form.action if set; otherwise use relative path (server must serve PHP)
    const endpoint = form.getAttribute('action') || '/api/create-payment.php';

    try {
      const formData = new FormData();
      Object.entries(payload).forEach(([k, v]) => formData.append(k, typeof v === 'undefined' || v === null ? '' : String(v)));

      const resp = await fetchWithTimeout(endpoint, {
        method: 'POST',
        body: formData
      }, 20000);

      console.log('DEBUG: response status', resp.status, resp.statusText);
      const contentType = resp.headers.get('content-type') || '';
      console.log('DEBUG: response content-type:', contentType);

      if (!resp.ok) {
        let text = '';
        try { text = await resp.text(); } catch (err) { text = `<could not read body: ${err}>`; }
        console.error('DEBUG: server returned non-ok status. Status:', resp.status);
        console.error('DEBUG: response body:', text);
        alert('Błąd serwera podczas tworzenia rezerwacji. Sprawdź konsolę (Network/Response).');
        setBtnLoading(false);
        return;
      }

      // Try to parse JSON safely
let data = null;
try {
    if (contentType.includes('application/json')) {
        data = await resp.json();
    } else {
        const txt = await resp.text();
        try {
            data = JSON.parse(txt);
        } catch (err) {
            console.error('Odpowiedź nie jest poprawnym JSON-em:', err);
            console.warn('Tekst odpowiedzi serwera:', txt);
            alert('Serwer zwrócił odpowiedź, która nie jest poprawnym JSON-em. Sprawdź konsolę.');
            setBtnLoading(false);
            return;
        }
    }
} catch (err) {
    console.error('Błąd podczas parsowania JSON:', err);
    alert('Nie udało się przetworzyć odpowiedzi serwera.');
    setBtnLoading(false);
    return;
}


      console.groupCollapsed('PAYMENT DEBUG: parsed response');
      console.log(data);
      console.groupEnd();

      if (data && data.p24_url && data.p24_fields && Object.keys(data.p24_fields).length > 0) {
        // create hidden form and submit to Przelewy24
        const p24form = document.createElement('form');
        p24form.method = 'POST';
        p24form.action = data.p24_url;
        p24form.style.display = 'none';
        Object.keys(data.p24_fields).forEach(k => {
          const ipt = document.createElement('input');
          ipt.type = 'hidden';
          ipt.name = k;
          ipt.value = String(data.p24_fields[k] ?? '');
          p24form.appendChild(ipt);
        });
        document.body.appendChild(p24form);
        p24form.submit();
        return;
      } else {
        console.warn('DEBUG: odpowiedź nie zawiera p24_url/p24_fields:', data);
        alert('Brak danych płatności w odpowiedzi serwera. Sprawdź konsolę (parsed response).');
        setBtnLoading(false);
        return;
      }
    } catch (err) {
      console.error('DEBUG: fetch/timeout error:', err);
      if (err && err.name === 'AbortError') {
        alert('Timeout: serwer nie odpowiedział na czas.');
      } else {
        alert('Błąd połączenia. Sprawdź konsolę.');
      }
      setBtnLoading(false);
      return;
    }
  });
});
const form = document.getElementById('bookingForm');
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const endpoint = '/api/create-payment.php'; // lub pełny URL do PHP
  const fd = new FormData(form); // zbierze pola z form
  try {
    const resp = await fetch(endpoint, { method: 'POST', body: fd });
    if (!resp.ok) throw new Error('Server error ' + resp.status);
    const data = await resp.json();
    if (data.ok && data.p24_url) {
      // utwórz formularz i submituj do P24 jak wcześniej
    } else {
      console.error('Błąd serwera', data);
      alert('Błąd: ' + (data.error || 'nieznany'));
    }
  } catch (err) {
    console.error(err);
    alert('Błąd połączenia z serwerem');
  }
});
