(function () {
    'use strict';

    var cfg = window.RK_CHAT_CFG;

    var rkTab = (function () {
        var m = window.location.search.match( /[?&]rk_tab=([a-f0-9]{40})/ );
        return m ? m[1] : '';
    }());

    function mkForm( fields ) {
        var fd = new FormData();
        fd.append( 'nonce', cfg.nonce );
        if ( rkTab ) fd.append( 'rk_tab', rkTab );
        Object.keys( fields ).forEach( function ( k ) { fd.append( k, fields[k] ); } );
        return fd;
    }

    var activePanel = 'coach';
    var pollTimers  = {};

    function escHtml( s ) {
        return String( s || '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function getBody( panel ) { return document.getElementById( 'rk-body-' + panel ); }

    function scrollBottom( panel ) {
        var el = getBody( panel );
        if ( el ) el.scrollTop = el.scrollHeight;
    }

    function buildMsg( msg, panel, prevSenderId ) {
        var isOut   = parseInt( msg.sender_id ) === parseInt( cfg.viewerId );
        var grouped = prevSenderId !== null && String(prevSenderId) === String(msg.sender_id);
        var cls     = 'rk-chat-msg ' + ( isOut ? 'rk-chat-msg--out' : 'rk-chat-msg--in' );
        if ( grouped ) cls += ' rk-chat-msg--grouped';

        var avaHtml = isOut ? '' :
            '<img class="rk-chat-msg__ava" src="' + escHtml( cfg[ panel ].avatar ) + '" alt="" width="34" height="34">';

        return '<div class="' + cls + '" data-id="' + parseInt( msg.id ) + '" data-sender="' + parseInt( msg.sender_id ) + '">'
            + avaHtml
            + '<div class="rk-chat-msg__bubble">'
            +   '<p class="rk-chat-msg__text">' + escHtml( msg.body ) + '</p>'
            +   '<time class="rk-chat-msg__time">' + escHtml( msg.time ) + ( isOut ? ' ✓' : '' ) + '</time>'
            + '</div>'
            + '</div>';
    }

    function autoResize( ta ) {
        ta.style.height = 'auto';
        ta.style.height = Math.min( ta.scrollHeight, 120 ) + 'px';
    }

    function sendMessage( panel, sendBtn, input ) {
        var text = input.value.trim();
        if ( ! text || sendBtn.disabled ) return;

        var toId = parseInt( sendBtn.dataset.to );
        if ( ! toId ) return;

        sendBtn.disabled = true;
        var tmpId = 'tmp-' + Date.now();

        var emptyEl = getBody( panel ).querySelector( '.rk-chat-empty' );
        if ( emptyEl ) emptyEl.remove();

        var lastEl = getBody( panel ).querySelector( '.rk-chat-msg:last-of-type' );
        var lastSender = lastEl ? lastEl.dataset.sender : null;
        var tmpHtml = '<div class="rk-chat-msg rk-chat-msg--out rk-chat-msg--sending'
            + ( lastSender !== null && String(lastSender) === String(cfg.viewerId) ? ' rk-chat-msg--grouped' : '' )
            + '" data-tmpid="' + tmpId + '" data-sender="' + cfg.viewerId + '">'
            + '<div class="rk-chat-msg__bubble">'
            +   '<p class="rk-chat-msg__text">' + escHtml( text ) + '</p>'
            +   '<time class="rk-chat-msg__time">جاري… ⏳</time>'
            + '</div></div>';

        getBody( panel ).insertAdjacentHTML( 'beforeend', tmpHtml );
        scrollBottom( panel );
        input.value = '';
        input.style.height = 'auto';

        var fd = mkForm( { action: 'rk_mc_chat_send', child_id: cfg.childId, to_id: toId, body: text, channel: panel } );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function(r) { return r.json(); } )
            .then( function(data) {
                var tmp = getBody( panel ).querySelector( '[data-tmpid="' + tmpId + '"]' );
                if ( data.success && data.data ) {
                    var d = data.data;
                    cfg[ panel ].lastId = Math.max( cfg[ panel ].lastId, d.id || 0 );
                    if ( tmp ) {
                        tmp.removeAttribute( 'data-tmpid' );
                        tmp.classList.remove( 'rk-chat-msg--sending' );
                        tmp.dataset.id     = d.id || 0;
                        tmp.dataset.sender = cfg.viewerId;
                        var timeEl = tmp.querySelector( '.rk-chat-msg__time' );
                        if ( timeEl ) timeEl.textContent = ( d.time || '' ) + ' ✓';
                    }
                } else {
                    if ( tmp ) {
                        tmp.classList.remove( 'rk-chat-msg--sending' );
                        tmp.classList.add( 'rk-chat-msg--error' );
                        var timeEl = tmp.querySelector( '.rk-chat-msg__time' );
                        if ( timeEl ) timeEl.textContent = '❌ فشل الإرسال';
                        tmp.title = ( data.data && data.data.msg ) ? data.data.msg : 'خطأ';
                    }
                }
            } )
            .catch( function() {
                var tmp = getBody( panel ).querySelector( '[data-tmpid="' + tmpId + '"]' );
                if ( tmp ) { tmp.classList.remove('rk-chat-msg--sending'); tmp.classList.add('rk-chat-msg--error'); }
            } )
            .finally( function() { sendBtn.disabled = false; input.focus(); } );
    }

    function pollPanel( panel ) {
        if ( ! cfg[ panel ].toId ) return;
        var fd = mkForm( { action: 'rk_mc_chat_load', child_id: cfg.childId, partner_id: cfg[ panel ].toId, last_id: cfg[ panel ].lastId } );

        fetch( cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
            .then( function(r) { return r.json(); } )
            .then( function(data) {
                if ( ! data.success || ! data.data || ! data.data.messages ) return;
                var msgs = data.data.messages;
                if ( ! msgs.length ) return;

                var body = getBody( panel );
                var emptyEl = body.querySelector( '.rk-chat-empty' );
                if ( emptyEl ) emptyEl.remove();

                var lastEl     = body.querySelector( '.rk-chat-msg:last-of-type' );
                var lastSender = lastEl ? parseInt( lastEl.dataset.sender ) : null;

                msgs.forEach( function(msg) {
                    // Skip message already rendered in DOM
                    if ( body.querySelector( '[data-id="' + msg.id + '"]' ) ) {
                        lastSender = parseInt( msg.sender_id );
                        cfg[ panel ].lastId = Math.max( cfg[ panel ].lastId, msg.id );
                        return;
                    }
                    // Race condition fix: if poll arrives before send-response for our own message,
                    // claim the optimistic (--sending) temp element instead of duplicating it.
                    if ( parseInt( msg.sender_id ) === cfg.viewerId ) {
                        var tmpEl = body.querySelector( '.rk-chat-msg--sending[data-sender="' + cfg.viewerId + '"]' );
                        if ( tmpEl ) {
                            tmpEl.dataset.id = msg.id;
                            tmpEl.removeAttribute( 'data-tmpid' );
                            tmpEl.classList.remove( 'rk-chat-msg--sending' );
                            var timeEl = tmpEl.querySelector( '.rk-chat-msg__time' );
                            if ( timeEl ) timeEl.textContent = ( msg.time || '' ) + ' ✓';
                            lastSender = parseInt( msg.sender_id );
                            cfg[ panel ].lastId = Math.max( cfg[ panel ].lastId, msg.id );
                            return;
                        }
                    }
                    body.insertAdjacentHTML( 'beforeend', buildMsg( msg, panel, lastSender ) );
                    lastSender = parseInt( msg.sender_id );
                    cfg[ panel ].lastId = Math.max( cfg[ panel ].lastId, msg.id );
                } );

                scrollBottom( panel );
            } )
            .catch( function() {} );
    }

    function startPoll( panel ) {
        if ( pollTimers[ panel ] || ! cfg[ panel ].toId ) return;
        pollTimers[ panel ] = setInterval( function() { pollPanel( panel ); }, 8000 );
    }
    function stopPoll( panel ) {
        if ( pollTimers[ panel ] ) { clearInterval( pollTimers[ panel ] ); pollTimers[ panel ] = null; }
    }

    /* 4.18.14 — session perdue (logout parent / expiration) : on arrête le
       polling immédiatement plutôt que de laisser 8 s de requêtes vouées au
       403. Émis par rk-session-sentinel.js. */
    window.addEventListener( 'rk:session-lost', function () {
        Object.keys( pollTimers ).forEach( stopPoll );
    } );

    /* Onglets */
    var tabs = document.querySelectorAll( '.rk-chat-tab' );
    if ( tabs.length ) {
        tabs.forEach( function(tab) {
            tab.addEventListener( 'click', function() {
                var next = this.dataset.panel;
                if ( next === activePanel ) return;
                tabs.forEach( function(t) { t.classList.remove('rk-chat-tab--active'); t.setAttribute('aria-selected','false'); } );
                this.classList.add('rk-chat-tab--active');
                this.setAttribute('aria-selected','true');
                document.querySelectorAll('.rk-chat-panel').forEach( function(p) { p.classList.remove('rk-chat-panel--active'); } );
                var np = document.getElementById( 'rk-panel-' + next );
                if ( np ) np.classList.add('rk-chat-panel--active');
                stopPoll( activePanel );
                activePanel = next;
                startPoll( activePanel );
                scrollBottom( activePanel );
            } );
        } );
    }

    /* Inputs */
    document.querySelectorAll('.rk-chat-input').forEach( function(input) {
        input.addEventListener('input', function() { autoResize(this); } );
        input.addEventListener('keydown', function(e) {
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                var panelId = this.id.replace('rk-input-','');
                var btn = document.querySelector('.rk-chat-send-btn[data-panel="' + panelId + '"]');
                if ( btn ) sendMessage( panelId, btn, this );
            }
        } );
    } );

    document.querySelectorAll('.rk-chat-send-btn').forEach( function(btn) {
        btn.addEventListener('click', function() {
            var panelId = this.dataset.panel;
            var input   = document.getElementById('rk-input-' + panelId);
            if ( input ) sendMessage( panelId, this, input );
        } );
    } );

    scrollBottom('coach');
    scrollBottom('admin');
    startPoll( activePanel );

    window.addEventListener('beforeunload', function() { Object.keys(pollTimers).forEach(stopPoll); } );

})();
