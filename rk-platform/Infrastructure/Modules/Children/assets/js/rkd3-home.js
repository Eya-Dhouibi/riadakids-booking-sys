document.querySelectorAll('#rkd3 .rk-countup').forEach(function(el){
    var target = parseInt(el.getAttribute('data-count-up') || '0', 10);
    if (!target) { el.textContent = '0'; return; }
    var start = 0, dur = 1200, step = target / dur * 16;
    function tick(){
        start += step;
        if (start >= target) { el.textContent = target.toLocaleString('ar'); return; }
        el.textContent = Math.floor(start).toLocaleString('ar');
        requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
});
(function(){
    var sidebar = document.getElementById('rkd3-sidebar'),
        overlay = document.getElementById('rkd3-drawer-overlay');
    if (!sidebar) return;
    function open(){ sidebar.classList.add('is-open'); if(overlay){overlay.hidden=false;overlay.removeAttribute('aria-hidden');} document.body.style.overflow='hidden'; document.querySelectorAll('.rkd3-burger').forEach(function(b){b.setAttribute('aria-expanded','true');}); }
    function close(){ sidebar.classList.remove('is-open'); if(overlay){overlay.hidden=true;overlay.setAttribute('aria-hidden','true');} document.body.style.overflow=''; document.querySelectorAll('.rkd3-burger').forEach(function(b){b.setAttribute('aria-expanded','false');}); }
    document.querySelectorAll('.rkd3-burger').forEach(function(b){ b.addEventListener('click', function(){ sidebar.classList.contains('is-open') ? close() : open(); }); });
    overlay && overlay.addEventListener('click', close);
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && sidebar.classList.contains('is-open')) close(); });
})();
