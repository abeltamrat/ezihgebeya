<?php
/** Central help layer for the PHP admin control center.
 * Adds visible field guidance and accessible action explanations to every admin form,
 * including controls introduced by future sections. Hidden transport fields are ignored. */
?>
<style>
  .admin-help-legend{display:flex;gap:10px;align-items:flex-start;margin:0 0 18px;padding:12px 14px;border:1px solid var(--line);border-radius:var(--r-sm);background:var(--brand-soft);color:var(--text);font-size:.84rem}
  .admin-help-legend strong{color:var(--brand-dark);white-space:nowrap}
  .admin-field-help{display:block;margin-top:5px;color:var(--text-2);font-size:.76rem;font-weight:500;line-height:1.45}
  .admin-field-help[data-risk="high"]{color:var(--red);font-weight:700}
  .admin-button-help{position:relative;display:inline-grid;place-items:center;width:20px;height:20px;margin-left:5px;border:1px solid var(--line-strong);border-radius:50%;background:var(--surface);color:var(--brand);font-size:.7rem;font-weight:900;cursor:help;vertical-align:middle}
  .admin-button-help::after{content:attr(data-help);position:absolute;z-index:120;left:50%;bottom:calc(100% + 8px);width:250px;padding:9px 11px;border-radius:9px;background:var(--ink);color:#fff;box-shadow:var(--shadow);font-size:.75rem;font-weight:600;line-height:1.4;opacity:0;pointer-events:none;transform:translate(-50%,5px);transition:.15s}
  .admin-button-help:hover::after,.admin-button-help:focus::after{opacity:1;transform:translate(-50%,0)}
  .admin-button-help[data-risk="high"]{border-color:var(--red);color:var(--red)}
  .admin-help-enhanced label:has(> .admin-field-help){align-content:start}
  @media(max-width:700px){.admin-help-legend{display:block}.admin-button-help::after{position:fixed;left:12px;right:12px;bottom:82px;width:auto;transform:none}.admin-button-help:hover::after,.admin-button-help:focus::after{transform:none}}
</style>
<script>
(() => {
  const root = document.querySelector('.dash-main');
  if (!root || root.dataset.adminHelpReady) return;
  root.dataset.adminHelpReady = '1';
  document.body.classList.add('admin-help-enhanced');

  const clean = value => String(value || '')
    .replace(/\[[^\]]*\]/g, ' ')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const sentence = value => {
    const text = clean(value);
    return text ? text.charAt(0).toUpperCase() + text.slice(1) : 'this value';
  };
  const labelFor = control => {
    const label = control.closest('label');
    if (label) {
      const clone = label.cloneNode(true);
      clone.querySelectorAll('input,select,textarea,button,.admin-field-help,.admin-button-help').forEach(node => node.remove());
      const text = clean(clone.textContent);
      if (text) return text;
    }
    return clean(control.getAttribute('aria-label') || control.name || control.id || control.placeholder || 'value');
  };

  const fieldRules = [
    [/password|secret|service.account|credential/i, 'Sensitive credential. Store it securely and do not share it in screenshots or support messages.', 'high'],
    [/android.sms.endpoint/i, 'Cloud mode: use https://api.sms-gate.app/3rdparty/v1/message. Local mode: use the phone address shown by the Android app, usually http://PHONE_IP:8080/message.'],
    [/android.sms.username/i, 'Basic Auth username displayed in the SMS Gateway for Android app.'],
    [/sms.provider/i, 'Select which service sends OTP and important notification messages. Outbox mode records messages without sending them.'],
    [/sms.gateway.url/i, 'Generic gateway template. Use {phone} and {message}; both values are URL-encoded before sending.'],
    [/phone/i, 'Enter a reachable phone number, including the country code when the recipient or gateway requires it.'],
    [/email/i, 'Enter a valid address used for account or operational communication.'],
    [/status|decision|verification.status/i, 'Choose the next workflow state carefully. This can change public visibility, access, fulfillment, or moderation results.'],
    [/role|account.type/i, 'Controls permissions and which dashboard the user can access. Grant administrative roles only to trusted staff.', 'high'],
    [/price|amount|budget|commission|credit|cost/i, 'Enter the monetary value in the configured marketplace currency. Review it before saving because it affects billing or reporting.', 'high'],
    [/payment.method/i, 'Select the channel used for this payment or accounting entry.'],
    [/reference/i, 'Enter the bank, wallet, or internal reference used to reconcile this transaction.'],
    [/start|end|expires|duration|months|weeks|days/i, 'Controls when this item starts, ends, or remains active. Confirm the date or duration before saving.'],
    [/priority|sort.order|weight|ranking/i, 'Higher values generally increase precedence, display order, or ranking influence.'],
    [/destination.url|announcement.url|webhook|url/i, 'Enter the complete destination. Test it and ensure it points to a trusted location before publishing.'],
    [/slug|key.name|setting.key/i, 'Stable machine-readable identifier. Use lowercase words separated by hyphens or underscores; changing it may affect existing links or data.'],
    [/title|name|label/i, 'Clear public or administrative name used to identify this record.'],
    [/description|body|message|note|instructions|response|reason/i, 'Explain the purpose and relevant details clearly. This text may be shown to users or retained in the audit trail.'],
    [/image|logo|icon|proof|document|restore.file|upload/i, 'Choose the correct file and verify it contains no unrelated or sensitive information before uploading.'],
    [/city|subcity|location|latitude|longitude|area/i, 'Used for geographic targeting, discovery, delivery, or marketplace reporting.'],
    [/category|parent.id|listing.type|market.type|placement/i, 'Determines where this item belongs and where it can appear in the marketplace.'],
    [/otp|required|enabled|mirror|auto.approve|maintenance|registration.open/i, 'Enable or disable this behavior. Changes to system switches apply immediately after saving.'],
  ];

  function fieldHelp(control) {
    const key = clean(`${control.name || ''} ${control.id || ''} ${labelFor(control)}`);
    for (const [pattern, help, risk] of fieldRules) {
      if (pattern.test(key)) return { help, risk: risk || '' };
    }
    const label = sentence(labelFor(control));
    if (control.type === 'checkbox' || control.type === 'radio') return { help: `Enable or select “${label}”. Review related options before saving.` };
    if (control.tagName === 'SELECT') return { help: `Choose the appropriate ${label.toLowerCase()} for this record.` };
    if (control.type === 'number' || control.type === 'range') return { help: `Enter the numeric ${label.toLowerCase()} within the allowed minimum and maximum.` };
    if (control.type === 'date' || control.type === 'datetime-local' || control.type === 'time') return { help: `Choose the applicable ${label.toLowerCase()} in your local time zone.` };
    if (control.type === 'file') return { help: `Select the correct file for ${label.toLowerCase()} and review it before uploading.` };
    return { help: `Enter ${label.toLowerCase()}. Review the value before submitting this form.` };
  }

  let fieldIndex = 0;
  root.querySelectorAll('form input:not([type="hidden"]):not([type="submit"]):not([type="button"]), form select, form textarea').forEach(control => {
    if (control.dataset.adminHelp || control.closest('[data-no-admin-help]')) return;
    // A field that already carries a hand-written .field-hint (System Settings, Ad Manager,
    // category attributes, Content Pages, Locations, Admins & Roles) doesn't need — and
    // shouldn't get — a second, more generic line stacked underneath it.
    const ownLabel = control.closest('label');
    if (ownLabel && ownLabel.querySelector('.field-hint')) return;
    control.dataset.adminHelp = '1';
    const { help, risk } = fieldHelp(control);
    const id = `admin-field-help-${++fieldIndex}`;
    const hint = document.createElement('small');
    hint.id = id;
    hint.className = 'admin-field-help';
    hint.textContent = help;
    if (risk) hint.dataset.risk = risk;
    const described = clean(control.getAttribute('aria-describedby'));
    control.setAttribute('aria-describedby', described ? `${described} ${id}` : id);
    const label = control.closest('label');
    if (label) label.appendChild(hint);
    else control.insertAdjacentElement('afterend', hint);
  });

  const actionRules = [
    [/delete|remove|ban|revoke|reset|restore|reject|cancel|stop|archive/i, 'This is a high-impact action and may remove access, visibility, data, or an active workflow. Confirm the target before continuing.', 'high'],
    [/approve|verify|activate|confirm|mark.*paid|payment/i, 'Confirms this record or financial action and may immediately notify users or activate linked services.', 'high'],
    [/backup|download|export/i, 'Creates or downloads a copy for safekeeping or review. Store exported data securely.'],
    [/migration|migrate|upgrade/i, 'Applies pending database schema changes. Take a current backup before running this in production.', 'high'],
    [/repair|optimize/i, 'Runs database maintenance. Use during a quiet period and keep a recent backup available.', 'high'],
    [/send test sms/i, 'Sends a real test through the currently saved SMS provider configuration. Carrier charges may apply.'],
    [/save|update|apply|set/i, 'Validates and saves the values in this form. Changes may become effective immediately.'],
    [/create|add|invite|submit|post/i, 'Creates or submits a new record using the values entered in this form.'],
    [/search|filter|view|open/i, 'Uses the selected criteria to display the relevant administrative records.'],
  ];
  let buttonIndex = 0;
  root.querySelectorAll('form button, form input[type="submit"], form input[type="button"]').forEach(button => {
    if (button.dataset.adminHelp || button.closest('[data-no-admin-help]')) return;
    button.dataset.adminHelp = '1';
    const form = button.closest('form');
    const action = form?.querySelector('input[name="do"]')?.value || button.value || '';
    const text = clean(button.textContent || button.value || action || 'Run action');
    let help = `Runs “${sentence(text)}” using the values in this form. Review the target and entered values before continuing.`;
    let risk = '';
    for (const [pattern, description, level] of actionRules) {
      if (pattern.test(`${text} ${action}`)) { help = description; risk = level || ''; break; }
    }
    button.title = help;
    button.setAttribute('aria-label', `${sentence(text)} — ${help}`);
    const info = document.createElement('span');
    info.className = 'admin-button-help';
    info.tabIndex = 0;
    info.setAttribute('role', 'note');
    info.setAttribute('aria-label', `Help for ${sentence(text)}: ${help}`);
    info.dataset.help = help;
    if (risk) info.dataset.risk = risk;
    info.textContent = '?';
    info.id = `admin-button-help-${++buttonIndex}`;
    button.insertAdjacentElement('afterend', info);
  });

  const legend = document.createElement('div');
  legend.className = 'admin-help-legend';
  legend.innerHTML = '<strong>Form help</strong><span>Guidance appears below every field. Hover or focus the <b>?</b> beside an action for details; red help markers identify high-impact actions.</span>';
  root.insertAdjacentElement('afterbegin', legend);
})();
</script>
