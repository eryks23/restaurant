document.addEventListener('DOMContentLoaded', () => {
  const q = (sel, root=document) => root.querySelector(sel);
  const qa = (sel, root=document) => Array.from((root||document).querySelectorAll(sel));

  // --- DOM ---
  const durationSelect = q('#duration');
  const participantsInput = q('#participants');
  const voucherInput = q('#voucher');
  const applyVoucherBtn = q('#applyVoucherBtn');
  const voucherFeedback = q('#voucherFeedback');

  const summaryDuration = q('#summaryDuration');
  const summaryDate = q('#summaryDate');
  const summaryTime = q('#summaryTime');
  const summaryPrice = q('#summaryPrice');

  const finalAmountField = q('#final_amount_grosze');
  const appliedVoucherField = q('#applied_voucher');
  const bookingSlotInput = q('#booking_slot_id'); // hidden input z calendar.js

  const slotsContainer = q('#slotsContainer');
  const timeInput = q('#timePicker');
  const datePicker = q('#datePicker');

  // --- Cena ---
  function getBasePricePerParticipant() {
    const opt = durationSelect.selectedOptions[0];
    return opt ? parseFloat(opt.getAttribute('data-price') || opt.value) : 0;
  }

  function applyVoucher(code, totalPrice) {
    if (!code) return { ok:false, message:'', newPrice: totalPrice };
    const normalized = code.trim().toUpperCase();
    if (normalized === 'PROMO100') return { ok:true, message:'Zastosowano rabat 100 zł', newPrice: Math.max(0,totalPrice-100), code:normalized };
    if (normalized === 'HALF') return { ok:true, message:'Zastosowano rabat 50%', newPrice: Math.max(0,Math.round(totalPrice*0.5)), code:normalized };
    return { ok:false, message:'Nieprawidłowy kod', newPrice: totalPrice };
  }

  function updatePriceSummary() {
    const perPerson = getBasePricePerParticipant();
    const participants = Math.max(1, parseInt(participantsInput.value,10) || 1);
    const baseTotal = perPerson * participants;
    const code = voucherInput.value.trim();
    const res = applyVoucher(code, baseTotal);
    const price = res.newPrice;

    summaryDuration.textContent = durationSelect.value + ' min';
    summaryDate.textContent = datePicker.value || '—';
    summaryTime.textContent = timeInput.value || '—';
    summaryPrice.textContent = price + ' zł';

    finalAmountField.value = Math.round(price * 100); // grosze
    appliedVoucherField.value = res.ok ? res.code : '';
    
    voucherFeedback.textContent = res.message;
    voucherFeedback.style.color = res.ok ? 'lightgreen' : 'var(--muted)';
  }

  // --- Integracja z calendar.js ---
  function attachSlotClickHandlers() {
    if (!slotsContainer) return;
    qa('.slot.available', slotsContainer).forEach(el => {
      if (el.dataset.bound) return; // zabezpieczenie przed wielokrotnym przypięciem
      el.dataset.bound = '1';
      el.addEventListener('click', () => {
        // zaznacz
        qa('.slot.selected', slotsContainer).forEach(x => x.classList.remove('selected'));
        el.classList.add('selected');

        const slotRaw = el.dataset.slotRaw ? JSON.parse(el.dataset.slotRaw) : null;
        const time = slotRaw?.time || el.textContent;
        const date = slotRaw?.date || datePicker.value;

        if (timeInput) timeInput.value = time;
        if (datePicker) datePicker.value = date;
        if (bookingSlotInput && slotRaw?.id) bookingSlotInput.value = slotRaw.id;

        summaryTime.textContent = time;
        summaryDate.textContent = date;

        updatePriceSummary();
      });
    });
  }

  // --- Eventy formularza ---
  durationSelect.addEventListener('change', updatePriceSummary);
  participantsInput.addEventListener('input', updatePriceSummary);
  participantsInput.addEventListener('change', updatePriceSummary);
  voucherInput.addEventListener('input', () => { voucherFeedback.textContent=''; updatePriceSummary(); });
  applyVoucherBtn.addEventListener('click', updatePriceSummary);

  datePicker.addEventListener('change', () => {
    summaryDate.textContent = datePicker.value || '—';
    updatePriceSummary();
    setTimeout(attachSlotClickHandlers, 50); // poczekaj, aż calendar.js wyrenderuje sloty
  });

  timeInput.addEventListener('input', updatePriceSummary);

  // --- Obserwuj slotsContainer na dynamiczne zmiany (calendar.js renderuje) ---
  const mo = new MutationObserver(() => {
    attachSlotClickHandlers();
  });
  if (slotsContainer) mo.observe(slotsContainer, { childList:true, subtree:true });

  // --- Inicjalizacja ---
  updatePriceSummary();
  attachSlotClickHandlers();
});
