@if(config('database.connections.saleprosaas_landlord'))
<style>
    .commission-ui {
        --bill-surface: #fff; --bill-soft: #f8f9fc; --bill-border: #e8eaf1;
        --bill-text: #202c40; --bill-muted: #69758a; --bill-primary: #7655bc;
        --bill-tint: #f3effb; --bill-success: #187a55; --bill-success-bg: #eaf8f1;
        --bill-warning: #936015; --bill-warning-bg: #fff5df;
        --bill-danger: #b33c4c; --bill-danger-bg: #fff0f1;
        color: var(--bill-text); padding-top: 28px; padding-bottom: 40px;
    }
    .dark-mode .commission-ui {
        --bill-surface: #202737; --bill-soft: #262f41; --bill-border: #394257;
        --bill-text: #edf0f7; --bill-muted: #b0b9ca; --bill-primary: #c4adf5;
        --bill-tint: #322943; --bill-success: #93dfba; --bill-success-bg: #203c34;
        --bill-warning: #f1cf8d; --bill-warning-bg: #403624;
        --bill-danger: #ffa9b4; --bill-danger-bg: #422a33;
    }
    .commission-ui *, .commission-ui *::before, .commission-ui *::after { box-sizing: border-box; }
    .commission-ui h1, .commission-ui h2, .commission-ui h3 { color: var(--bill-text); font-weight: 650; }
    .commission-ui h1 { font-size: clamp(23px, 2.5vw, 30px); margin: 0 0 8px; letter-spacing: -.025em; }
    .commission-ui h2 { font-size: 17px; margin: 0; }
    .commission-ui h3 { font-size: 15px; margin: 0 0 12px; }
    .commission-ui p { margin: 0; }
    .commission-ui a:not(.btn) { color: var(--bill-primary); }
    .commission-ui .billing-muted { color: var(--bill-muted); font-size: 13px; line-height: 1.65; }
    .commission-ui .billing-kicker { display: block; color: var(--bill-primary); font-size: 11px; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; margin-bottom: 8px; }
    .commission-ui .billing-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 18px; margin-bottom: 26px; }
    .commission-ui .billing-back { display: inline-flex; gap: 7px; align-items: center; margin-bottom: 20px; font-size: 13px; font-weight: 600; }
    .commission-ui .billing-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .commission-ui .billing-grid { display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(300px, 1fr); gap: 24px; align-items: start; }
    .commission-ui .billing-stack { display: grid; gap: 22px; min-width: 0; }
    .commission-ui .billing-card { background: var(--bill-surface); border: 1px solid var(--bill-border); border-radius: 16px; overflow: hidden; box-shadow: 0 4px 18px rgba(24, 39, 68, .025); min-width: 0; }
    .commission-ui .billing-card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 21px 24px; border-bottom: 1px solid var(--bill-border); }
    .commission-ui .billing-card-head h2 + p { margin-top: 5px; }
    .commission-ui .billing-card-body { padding: 24px; }
    .commission-ui .billing-icon { width: 42px; height: 42px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: var(--bill-tint); color: var(--bill-primary); font-size: 22px; flex-shrink: 0; }
    .commission-ui .billing-title { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .commission-ui .billing-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; margin-bottom: 26px; }
    .commission-ui .billing-stat { padding: 22px; display: flex; gap: 16px; align-items: flex-start; }
    .commission-ui .billing-stat-label { display: block; color: var(--bill-muted); font-size: 12px; margin-bottom: 8px; }
    .commission-ui .billing-stat-value { display: block; font-size: clamp(22px, 2.2vw, 29px); font-weight: 700; line-height: 1.2; letter-spacing: -.025em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .commission-ui .billing-stat-value small { font-size: 12px; font-weight: 500; letter-spacing: 0; }
    .commission-ui .billing-stat .billing-muted { display: block; margin-top: 9px; font-size: 12px; }
    .commission-ui .billing-badge { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 7px; font-size: 12px; font-weight: 600; line-height: 1.4; background: var(--bill-tint); color: var(--bill-primary); white-space: nowrap; }
    .commission-ui .billing-badge::before { content: ''; width: 6px; height: 6px; background: currentColor; border-radius: 50%; flex-shrink: 0; }
    .commission-ui .billing-badge-due { background: var(--bill-warning-bg); color: var(--bill-warning); }
    .commission-ui .billing-badge-paid, .commission-ui .billing-badge-no_charge { background: var(--bill-success-bg); color: var(--bill-success); }
    .commission-ui .billing-badge-blocked { background: var(--bill-danger-bg); color: var(--bill-danger); }
    .commission-ui .billing-reported { display: block; margin-top: 6px; color: var(--bill-warning); font-size: 12px; }
    .commission-ui .billing-amount { background: var(--bill-tint); padding: 24px; border-bottom: 1px solid var(--bill-border); }
    .commission-ui .billing-amount strong { display: block; font-size: clamp(28px, 3vw, 38px); line-height: 1.25; letter-spacing: -.035em; font-variant-numeric: tabular-nums; margin: 8px 0; overflow-wrap: anywhere; }
    .commission-ui .billing-amount strong small { font-size: 15px; font-weight: 500; letter-spacing: 0; }
    .commission-ui .billing-lines { margin: 0; }
    .commission-ui .billing-line { display: flex; justify-content: space-between; align-items: baseline; gap: 18px; padding: 13px 0; border-bottom: 1px solid var(--bill-border); }
    .commission-ui .billing-line:last-child { border-bottom: 0; }
    .commission-ui .billing-line dt { color: var(--bill-muted); font-weight: 400; font-size: 13px; }
    .commission-ui .billing-line dd { margin: 0; font-weight: 600; font-size: 14px; text-align: end; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .commission-ui .billing-line-total dt, .commission-ui .billing-line-total dd { color: var(--bill-text); font-weight: 700; }
    .commission-ui .billing-formula { padding: 15px 17px; border-radius: 10px; background: var(--bill-soft); margin-top: 18px; color: var(--bill-muted); font-size: 13px; line-height: 1.7; }
    .commission-ui .billing-formula strong { color: var(--bill-text); }
    .commission-ui .billing-details { margin-top: 20px; border-top: 1px solid var(--bill-border); padding-top: 16px; }
    .commission-ui .billing-details summary { color: var(--bill-primary); font-weight: 600; font-size: 13px; cursor: pointer; }
    .commission-ui .billing-details[open] summary { margin-bottom: 12px; }
    .commission-ui .billing-note { display: flex; gap: 10px; padding: 14px 16px; border-radius: 10px; background: var(--bill-tint); color: var(--bill-primary); font-size: 13px; line-height: 1.7; margin-bottom: 20px; }
    .commission-ui .billing-note > i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }
    .commission-ui .billing-note-warning { color: var(--bill-warning); background: var(--bill-warning-bg); }
    .commission-ui .billing-note-success { color: var(--bill-success); background: var(--bill-success-bg); }
    .commission-ui .billing-note-danger { color: var(--bill-danger); background: var(--bill-danger-bg); }
    .commission-ui .billing-field { margin-bottom: 18px; min-width: 0; }
    .commission-ui .billing-field label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
    .commission-ui .billing-field .form-control { width: 100%; min-height: 44px; border: 1px solid var(--bill-border); border-radius: 9px; background: var(--bill-surface); color: var(--bill-text); font-size: 14px; padding: 10px 12px; }
    .commission-ui .billing-field .form-control[readonly] { background: var(--bill-soft); }
    .commission-ui .billing-field textarea.form-control { height: auto; min-height: 130px; resize: vertical; line-height: 1.7; }
    .commission-ui .billing-field .billing-muted { display: block; margin-top: 7px; }
    .commission-ui .billing-field .is-invalid { border-color: var(--bill-danger); }
    .commission-ui .billing-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .commission-ui .billing-check { display: flex; align-items: flex-start; gap: 10px; border: 1px solid var(--bill-border); border-radius: 10px; padding: 15px; margin: 0 0 18px; color: var(--bill-muted); font-size: 12px; line-height: 1.75; cursor: pointer; }
    .commission-ui .billing-check input { margin-top: 4px; flex-shrink: 0; width: 16px; height: 16px; accent-color: #7655bc; }
    .commission-ui .btn { font-size: 13px; font-weight: 600; border-radius: 9px; padding: 11px 17px; white-space: normal; }
    .commission-ui .btn-primary { background: #7655bc; border-color: #7655bc; color: #fff; }
    .commission-ui .btn-primary:hover { background: #6343a5; border-color: #6343a5; }
    .commission-ui .billing-btn-soft { background: var(--bill-soft); border: 1px solid var(--bill-border); color: var(--bill-text); }
    .commission-ui .billing-btn-full { width: 100%; }
    .commission-ui :is(a, button, input, textarea, select, summary):focus-visible { outline: 3px solid #b79ade; outline-offset: 3px; }
    .commission-ui .billing-report { white-space: pre-wrap; overflow-wrap: anywhere; background: var(--bill-soft); border: 1px solid var(--bill-border); border-radius: 10px; padding: 18px; font-size: 14px; line-height: 1.8; }
    .commission-ui .billing-timeline { list-style: none; padding: 0; margin: 0; }
    .commission-ui .billing-timeline li { display: flex; gap: 12px; padding: 0 0 22px; }
    .commission-ui .billing-timeline li:last-child { padding-bottom: 0; }
    .commission-ui .billing-step { display: flex; align-items: center; justify-content: center; width: 27px; height: 27px; flex-shrink: 0; border-radius: 50%; background: var(--bill-soft); color: var(--bill-muted); border: 1px solid var(--bill-border); font-size: 12px; }
    .commission-ui .billing-step-done { background: var(--bill-success-bg); color: var(--bill-success); border-color: transparent; }
    .commission-ui .billing-timeline strong { display: block; font-size: 13px; margin-bottom: 3px; }
    .commission-ui .billing-timeline time { display: block; color: var(--bill-muted); font-size: 12px; }
    .commission-ui .billing-section-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 0 0 16px; }
    .commission-ui .billing-invoice-top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding-bottom: 20px; border-bottom: 1px solid var(--bill-border); margin-bottom: 20px; }
    .commission-ui .billing-invoice-top h3 { font-size: 17px; margin-bottom: 6px; }
    .commission-ui .billing-invoice-amount { font-size: 24px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .commission-ui .billing-terms { padding-inline-start: 20px; margin: 0; color: var(--bill-muted); font-size: 13px; line-height: 1.85; }
    .commission-ui .billing-terms li + li { margin-top: 10px; }
    .commission-ui .billing-payment-detail + .billing-payment-detail { margin-top: 18px; }
    .commission-ui .billing-payment-detail dt { font-size: 12px; color: var(--bill-muted); margin-bottom: 6px; }
    .commission-ui .billing-payment-detail dd { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; margin: 0; }
    .commission-ui .billing-prepare { display: flex; gap: 16px; align-items: flex-end; }
    .commission-ui .billing-prepare .billing-field { flex: 1; margin: 0; }
    .commission-ui .billing-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 0; color: var(--bill-text); }
    .commission-ui .billing-table th { background: var(--bill-soft); color: var(--bill-muted); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .035em; padding: 14px 20px; white-space: nowrap; border-bottom: 1px solid var(--bill-border); }
    .commission-ui .billing-table td { padding: 20px; border-bottom: 1px solid var(--bill-border); vertical-align: middle; }
    .commission-ui .billing-table tr:last-child td { border-bottom: 0; }
    .commission-ui .billing-table tbody tr:hover { background: var(--bill-soft); }
    .commission-ui .billing-table .billing-numeric { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .commission-ui .billing-empty { padding: 42px 24px; text-align: center; }
    .commission-ui .billing-empty .billing-icon { margin-bottom: 16px; }
    .commission-ui .billing-empty h3 { margin-bottom: 8px; }
    .commission-ui .billing-pagination { margin-top: 22px; }
    @media (max-width: 1100px) {
        .commission-ui .billing-grid { grid-template-columns: minmax(0, 1fr); }
        .commission-ui .billing-stat { padding: 18px; gap: 12px; }
        .commission-ui .billing-stat .billing-icon { display: none; }
    }
    @media (max-width: 600px) {
        .commission-ui { padding-top: 20px; }
        .commission-ui .billing-stats { grid-template-columns: 1fr; gap: 10px; }
        .commission-ui .billing-stat .billing-icon { display: inline-flex; }
        .commission-ui .billing-card-head, .commission-ui .billing-card-body, .commission-ui .billing-amount { padding: 18px; }
        .commission-ui .billing-form-row { grid-template-columns: 1fr; gap: 0; }
        .commission-ui .billing-prepare { align-items: stretch; flex-direction: column; }
        .commission-ui .billing-line { gap: 12px; }
        .commission-ui .billing-line dd { max-width: 55%; }
    }
</style>
@endif
