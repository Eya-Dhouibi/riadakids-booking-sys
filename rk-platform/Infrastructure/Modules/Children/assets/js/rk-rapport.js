function rkRapportPDF(btn) {
    var orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> جاري إنشاء PDF…';

    function doExport() {
        var el   = document.querySelector('.rp-card');
        var name = (document.querySelector('.rp-header-name') || {textContent:''}).textContent.trim();
        var per  = (document.querySelector('.rp-period-pill') || {textContent:''}).textContent.trim();
        var ctrl = document.querySelector('.rp-header-controls');
        var tabs = document.querySelector('.rp-child-filter');
        if (ctrl) ctrl.style.visibility = 'hidden';
        if (tabs) tabs.style.visibility = 'hidden';
        html2pdf().set({
            margin      : [8, 8, 8, 8],
            filename    : 'تقرير-' + name + '-' + per + '.pdf',
            image       : { type: 'jpeg', quality: 0.95 },
            html2canvas : { scale: 2, useCORS: true, scrollX: 0, scrollY: 0 },
            jsPDF       : { unit: 'mm', format: 'a4', orientation: 'portrait' }
        }).from(el).save().then(function() {
            if (ctrl) ctrl.style.visibility = '';
            if (tabs) tabs.style.visibility = '';
            btn.disabled  = false;
            btn.innerHTML = orig;
        }).catch(function() {
            if (ctrl) ctrl.style.visibility = '';
            if (tabs) tabs.style.visibility = '';
            btn.disabled  = false;
            btn.innerHTML = orig;
        });
    }

    if (window.html2pdf) {
        doExport();
    } else {
        var s   = document.createElement('script');
        s.src   = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        s.onload  = doExport;
        s.onerror = function() {
            btn.disabled  = false;
            btn.innerHTML = orig;
            alert('تعذّر إنشاء PDF. يرجى المحاولة مرة أخرى.');
        };
        document.head.appendChild(s);
    }
}
