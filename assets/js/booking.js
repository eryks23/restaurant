// assets/js/booking.js
(function initValidation() {
  'use strict';

  var form = document.querySelector('form#bookingForm') || document.querySelector('form');
  if (!form) return;

  var qs = function (selector) {
    return form.querySelector(selector);
  };
  var qsa = function (selector) {
    return Array.from(form.querySelectorAll(selector));
  };
  var create = function (tag, cls) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    return e;
  };

  // pola formularza (dokładniejsze mapowanie)
  var fields = {
    firstName: qs('[name="firstName"]'),
    lastName: qs('[name="lastName"]'),
    email: qs('[name="email"]'),
    phone: qs('[name="phone"]'),
    booking_duration: qs('[name="booking_duration"]'),
    booking_slot_id: qs('[name="booking_slot_id"], input#booking_slot_id'),
    rodo: qs('[name="rodo"]')
  };

  // podsumowanie walidacji
  var summary = document.getElementById('validationSummary');
  if (!summary) {
    summary = create('div');
    summary.id = 'validationSummary';
    summary.setAttribute('aria-live', 'polite');
    summary.style.margin = '8px 0';
    form.insertBefore(summary, form.firstChild);
  }

  function getError(field) {
    if (!field) return null;
    var el = field.nextElementSibling;
    if (el && el.classList && el.classList.contains('field-error')) return el;
    el = create('div', 'field-error');
    el.setAttribute('aria-live', 'polite');
    el.style.color = '#b91c1c';
    el.style.fontSize = '13px';
    el.style.marginTop = '4px';
    if (field.parentNode) field.parentNode.insertBefore(el, field.nextSibling);
    return el;
  }

  function rules(name, value, all) {
    if (value == null) value = ''; else value = String(value);
    switch (name) {
      case 'firstName':
      case 'lastName':
        return value.trim().length < 2 ? 'Wprowadź co najmniej 2 znaki' : true;
      case 'email':
        return !/^\S+@\S+\.\S+$/.test(value) ? 'Niepoprawny adres e-mail' : true;
      case 'phone':
        return !/^\+?[0-9\s\-]{7,20}$/.test(value) ? 'Niepoprawny numer telefonu (7-20 cyfr)' : true;
      case 'booking_duration':
        return !value ? 'Wybierz czas jazdy' : true;
      case 'booking_slot_id':
        return !value ? 'Wybierz slot rezerwacji' : true;
      case 'rodo':
        if (!(all.rodo === true || all.rodo === 'on' || all.rodo === 'checked' || String(all.rodo) === 'true')) {
          return 'Musisz zaakceptować regulamin RODO';
        }
        return true;
      default:
        return true;
    }
  }

  function getValues(f = form) {
    var data = {};
    var fd = new FormData(f);
    for (var pair of fd.entries()) {
      data[pair[0]] = pair[1];
    }
    // checkbox RODO -> boolean
    if (fields.rodo) {
      data.rodo = !!fields.rodo.checked;
    } else {
      if (data.rodo === undefined) data.rodo = false;
      else {
        var val = String(data.rodo).toLowerCase();
        data.rodo = (val === 'on' || val === 'true');
      }
    }
    return data;
  }

  function validateField(name) {
    var field = fields[name];
    if (!field) return true;
    var all = getValues();
    var value;
    if (field.type === 'checkbox') value = all[name];
    else value = field.value;
    var res = rules(name, value, all);
    var err = getError(field);
    field.classList.remove('invalid', 'valid');
    if (res === true) {
      field.classList.add('valid');
      if (err) err.textContent = '';
      return true;
    } else {
      field.classList.add('invalid');
      if (err) err.textContent = res;
      return res;
    }
  }

  function validateAll() {
    var names = Object.keys(fields);
    var errors = {};
    for (var n of names) {
      var r = validateField(n);
      if (r !== true) errors[n] = r;
    }
    return errors;
  }
  window.validateAll = validateAll;

  function debounce(fn, ms) {
    if (ms === undefined) ms = 200;
    var timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(function () {
        fn(...args);
      }, ms);
    };
  }

  function createEl(tag, props) {
    if (props === undefined) props = {};
    var el = document.createElement(tag);
    for (var key in props) {
      el[key] = props[key];
    }
    return el;
  }

  // podpinamy eventy walidacyjne
  Object.entries(fields).forEach(function ([name, el]) {
    if (!el) return;
    var isTextInput = el.tagName && el.tagName.toLowerCase() === 'input' && ['text', 'email', 'tel'].indexOf(el.type) !== -1;
    if (isTextInput) {
      el.addEventListener('input', debounce(function () { validateField(name); }, 180));
      el.addEventListener('blur', function () { validateField(name); });
    } else {
      el.addEventListener('change', function () { validateField(name); });
      el.addEventListener('blur', function () { validateField(name); });
    }
  });

  // ---- TOAST helper ----
  function showToast(message, type = 'error') {
    var toast = document.createElement('div');
    toast.textContent = message;
    toast.style.position = 'fixed';
    toast.style.bottom = '20px';
    toast.style.right = '20px';
    toast.style.background = type === 'error' ? '#ef4444' : '#22c55e';
    toast.style.color = '#fff';
    toast.style.padding = '10px 14px';
    toast.style.borderRadius = '6px';
    toast.style.boxShadow = '0 2px 6px rgba(0,0,0,0.2)';
    toast.style.zIndex = 9999;
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 4000);
  }

  // ---- local initiate payment fallback (jeśli nie ma globalnego init) ----
  function initiatePaymentLocal(paymentData, formTarget = '_self') {
    try {
      var p24_url = paymentData.p24_url;
      var p24_fields = paymentData.p24_fields || {};
      if (!p24_url) {
        console.error('Brak p24_url w paymentData', paymentData);
        showToast('Brak danych do płatności', 'error');
        return;
      }
      var f = document.createElement('form');
      f.method = 'POST';
      f.action = p24_url;
      f.target = formTarget;
      f.style.display = 'none';
      for (var key in p24_fields) {
        if (!Object.prototype.hasOwnProperty.call(p24_fields, key)) continue;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = String(p24_fields[key]);
        f.appendChild(input);
      }
      document.body.appendChild(f);
      f.submit();
    } catch (err) {
      console.error('initiatePaymentLocal error', err);
      showToast('Błąd podczas inicjowania płatności', 'error');
    }
  }

  // ---- Setup booking submit handler ----
  (function setupBookingForm() {
    if (!form) return;
    var submitBtn = form.querySelector('#submitBtn');
    if (!submitBtn) return;

    // helper validate booking_slot_id (hidden)
    function validateBookingSlot() {
      var field = fields.booking_slot_id;
      if (!field) return true;
      var value = String(field.value || '').trim();
      var err = field.nextElementSibling;
      if (!err || !err.classList || !err.classList.contains('field-error')) {
        err = document.createElement('div');
        err.className = 'field-error';
        err.style.color = '#b91c1c';
        err.style.fontSize = '13px';
        err.style.marginTop = '4px';
        var parent = field.parentNode;
        var next = field.nextSibling;
        if (next) parent.insertBefore(err, next); else parent.appendChild(err);
      }
      if (!value) {
        err.textContent = 'Wybierz slot rezerwacji';
        field.classList.add('invalid');
        return false;
      } else {
        err.textContent = '';
        field.classList.remove('invalid');
        field.classList.add('valid');
        return true;
      }
    }

    form.addEventListener('submit', async function (e) {
      e.preventDefault();

      // walidacja
      var validSlot = validateBookingSlot();
      var allErrors = window.validateAll ? window.validateAll() : {};
      if (!validSlot) allErrors.booking_slot_id = 'Wybierz slot rezerwacji';
      if (Object.keys(allErrors).length > 0) {
        showToast("Proszę poprawić błędy w formularzu", 'error');
        // optionally focus first invalid
        var firstInvalid = form.querySelector('.invalid');
        if (firstInvalid) firstInvalid.focus();
        return;
      }

      // blokada przycisku
      submitBtn.disabled = true;
      var originalText = submitBtn.textContent;
      submitBtn.textContent = 'Ładowanie...';

      try {
        var formData = new FormData(form);

        // próbujemy bez rozszerzenia .php najpierw (nowocześniejsze API), potem fallback
        var tryUrls = ['/api/create-booking', '/api/create-booking.php'];
        var resp = null;
        var data = null;
        var successResponse = false;
        for (var i = 0; i < tryUrls.length; i++) {
          try {
            resp = await fetch(tryUrls[i], {
              method: 'POST',
              body: formData
            });
            // jeśli 404 lub inny błąd status — spróbuj kolejny
            if (!resp.ok) {
              console.warn('Create booking returned status', resp.status, 'for', tryUrls[i]);
              continue;
            }
            data = await resp.json();
            successResponse = true;
            break;
          } catch (err) {
            console.warn('Błąd sieci przy', tryUrls[i], err);
            continue;
          }
        }

        if (!successResponse) {
          // brak działającego backendu — fallback demo: zainicjuj sandbox (tylko test)
          showToast('Serwer rezerwacji niedostępny — testowe przekierowanie do sandboxu', 'error');
          var fallbackData = {
            p24_url: 'https://sandbox.przelewy24.pl/trnRequest',
            p24_fields: {
              amount: 19900,
              currency: 'PLN',
              description: 'Rezerwacja symulatora Vectron - TEST',
              email: form.querySelector('[name="email"]')?.value || ''
            },
            booking_id: 'FALLBACK-' + Date.now()
          };
          if (typeof window.initiatePayment === 'function') {
            window.initiatePayment(fallbackData, '_self');
          } else if (typeof window.initPayment === 'function') {
            window.initPayment(fallbackData, '_self');
          } else {
            initiatePaymentLocal(fallbackData, '_self');
          }
          return;
        }

        // Obsługa odpowiedzi backendu
        // możliwe kształty odpowiedzi:
        // { success: true, p24_url, p24_fields, booking_id, amount }
        // albo { p24_url, p24_fields, booking_id } (bez success)
        // albo { redirect_url: "..." }
        if (data) {
          if (data.redirect_url) {
            // backend chce bezpośredniego redirectu (np. już przygotował P24 sesję)
            window.location.href = data.redirect_url;
            return;
          }
          var paymentPayload = null;
          if (data.success === false) {
            showToast(data.message || 'Błąd podczas tworzenia rezerwacji', 'error');
            return;
          }
          if (data.p24_url && data.p24_fields) {
            paymentPayload = {
              p24_url: data.p24_url,
              p24_fields: data.p24_fields,
              booking_id: data.booking_id || null
            };
          } else if (data.payment && data.payment.p24_url) {
            paymentPayload = {
              p24_url: data.payment.p24_url,
              p24_fields: data.payment.p24_fields || {},
              booking_id: data.booking_id || null
            };
          } else {
            // nie ma danych płatności -> być może backend zwrócił tylko success i link
            if (data.success && data.message) {
              showToast(data.message || 'Rezerwacja utworzona', 'success');
              return;
            } else {
              showToast('Serwer nie zwrócił danych płatności', 'error');
              console.warn('Nieoczekiwany payload z create-booking:', data);
              return;
            }
          }

          // wywołaj preferowane API płatności (globalne) lub lokalny fallback
          if (typeof window.initiatePayment === 'function') {
            window.initiatePayment(paymentPayload, '_self', function (id) { console.log('Payment started', id); });
          } else if (typeof window.initPayment === 'function') {
            // obsługa starszej nazwy
            window.initPayment(paymentPayload, '_self');
          } else {
            initiatePaymentLocal(paymentPayload, '_self');
          }
        } else {
          showToast('Błąd odpowiedzi serwera', 'error');
        }
      } catch (err) {
        console.error(err);
        showToast('Błąd sieci lub serwera', 'error');
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    });
  }());
})();
