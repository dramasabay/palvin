// PALVIN v2 — App JS
// Language toggle persistence
(function(){
    const q = new URLSearchParams(window.location.search);
    if(q.has('lang')){
        const lang = q.get('lang');
        if(['en','km'].includes(lang)){
            sessionStorage.setItem('palvin_lang', lang);
        }
    }
})();
