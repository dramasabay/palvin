        </main><!-- end main -->
    </div><!-- end flex-1 -->
</div><!-- end flex min-h-screen -->

<script>
// Live clock
(function(){
    function pad(n){ return String(n).padStart(2,'0'); }
    function tick(){
        var d = new Date();
        var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var str = pad(d.getDate())+' '+months[d.getMonth()]+' '+d.getFullYear()+' '+pad(d.getHours())+':'+pad(d.getMinutes());
        var el = document.getElementById('live-clock');
        if(el) el.textContent = str;
    }
    tick();
    setInterval(tick, 30000);
})();
// Sidebar toggle (mobile)
function toggleSidebar(open){
    var sb = document.getElementById('main-sidebar');
    var ov = document.getElementById('sidebar-overlay');
    if(open){ sb.classList.add('open'); ov.classList.add('active'); }
    else { sb.classList.remove('open'); ov.classList.remove('active'); }
}
// Scroll to top button
(function(){
    var btn = document.getElementById('scroll-top');
    if(!btn) return;
    window.addEventListener('scroll', function(){
        btn.classList.toggle('visible', window.scrollY > 300);
    }, {passive:true});
})();
// Confirm delete
document.addEventListener('click', function(e){
    var btn = e.target.closest('[data-confirm]');
    if(btn && !confirm(btn.getAttribute('data-confirm')||'Are you sure?')){
        e.preventDefault();
    }
});
</script>
</body>
</html>
