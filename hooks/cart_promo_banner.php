<?php
/**
 * A promotion code box at the top of the shopping cart, pre-filled and one
 * click from applied, for the WHMCS 9 store cart (the React "Nexus" cart).
 *
 * The store's domain step drops a ?promocode= from the URL and the cart does
 * not apply a code from the address bar, so a campaign link alone does not
 * work. This hook shows the code above the cart's own promotion box with a
 * Copy button that also fills the field; once the code is on the cart the
 * box turns into a confirmation with a remove button.
 *
 * The Nexus cart renders inside an open shadow root on #nexus-root, so the
 * notice and its styles are inserted into that shadow root and the applied
 * state is read from the cart API the app itself uses.
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

function cpb_config(): array
{
    return [
        'code' => '',                       // the promotion code to show (required)
        'eyebrow' => 'Launch offer',
        'title' => '20% off your first invoice',
        'note' => 'Paste the code into the box below and press Apply.',
        'applied_eyebrow' => 'Code applied',
        'applied_title' => 'Your discount is on this order.',
        'applied_note' => 'Changed your mind? Press × to remove the code.',
        'accent' => '#2563eb',              // border and button colour
    ];
}

add_hook('ClientAreaFooterOutput', 1, function (array $vars): string {
    $c = cpb_config();
    if ($c['code'] === '' || ($vars['filename'] ?? '') !== 'cart' || !in_array((string) ($_GET['a'] ?? 'view'), ['view', ''], true)) {
        return '';
    }
    $cfg = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    return <<<HTML
<script>
(function(){
  var C={$cfg}, CODE=C.code, host=document.getElementById('nexus-root');
  if(!host){return;}
  var CSS='.cpb{margin:0 0 16px;padding:22px 20px 20px;border:2px dashed '+C.accent+';text-align:center;background:#fff;color:#000;width:100%;box-sizing:border-box;font-family:inherit}'
    +'.cpb.is-applied{background:'+C.accent+';color:#fff;border-style:solid}'
    +'.cpb p{margin:0}'
    +'.cpb-eyebrow{margin:0 0 8px!important;font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:'+C.accent+';font-weight:600}'
    +'.cpb.is-applied .cpb-eyebrow{color:rgba(255,255,255,.75)}'
    +'.cpb-code{display:inline-flex;align-items:stretch;border:2px solid '+C.accent+';margin:0 0 12px}'
    +'.cpb.is-applied .cpb-code{border-color:#fff}'
    +'.cpb-code code{padding:10px 18px;font-family:ui-monospace,Menlo,monospace;font-size:26px;line-height:1;font-weight:700;letter-spacing:.1em;background:#fff;color:'+C.accent+'}'
    +'.cpb.is-applied .cpb-code code{background:'+C.accent+';color:#fff}'
    +'.cpb-code button{border:0;border-left:2px solid '+C.accent+';background:'+C.accent+';color:#fff;padding:0 16px;font:inherit;font-weight:700;font-size:13px;cursor:pointer}'
    +'.cpb.is-applied .cpb-code button{border-left-color:#fff;background:#fff;color:'+C.accent+';font-size:18px;line-height:1;padding:0 14px}'
    +'.cpb-title{margin:0 0 4px!important;font-size:15px;font-weight:700;line-height:1.4}'
    +'.cpb-note{margin:0!important;font-size:12.5px;opacity:.75;line-height:1.5}'
    +'.cpb.is-busy{opacity:.6;pointer-events:none}';
  var applied=null, tag=null, checking=false, lastCheck=0;
  try{tag=JSON.parse(atob(host.getAttribute('data-init')||'')).cartTag||null;}catch(e){}
  function sr(){return host.shadowRoot;}
  function text(n,t){n.textContent=t;return n;}
  function card(){
    var root=sr(); if(!root){return null;}
    var input=null, inputs=root.querySelectorAll('input');
    for(var i=0;i<inputs.length;i++){if(/promo/i.test(inputs[i].placeholder||'')){input=inputs[i];break;}}
    var tab=null, btns=root.querySelectorAll('button');
    for(var j=0;j<btns.length;j++){if(/^apply promo code$/i.test((btns[j].textContent||'').trim())){tab=btns[j];break;}}
    var a=input||tab; if(!a){return null;}
    var el=a;
    while(el&&el.parentElement&&el.parentElement!==root){
      el=el.parentElement;
      var cls=(el.className||'').toString();
      if(/\\bborder\\b/.test(cls)&&/\\bw-full\\b/.test(cls)&&/\\bp-4\\b/.test(cls)){return {card:el,input:input};}
    }
    return {card:a.parentElement,input:input};
  }
  function fill(input){
    if(!input||input.value){return;}
    var setter=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;
    setter.call(input,CODE);
    input.dispatchEvent(new Event('input',{bubbles:true}));
  }
  function stateHeader(){
    return fetch('/index.php?rp=/api/v2/cart/'+encodeURIComponent(tag),{credentials:'same-origin',headers:{accept:'application/json'}})
      .then(function(r){return r.headers.get('x-api-state')||'';});
  }
  function removePromo(box){
    box.classList.add('is-busy');
    stateHeader().then(function(st){
      var h={accept:'application/json','content-type':'application/json'}; if(st){h['x-api-state']=st;}
      return fetch('/index.php?rp=/api/v2/cart/'+encodeURIComponent(tag)+'/promotions',{method:'DELETE',credentials:'same-origin',headers:h,body:JSON.stringify({code:CODE})});
    }).then(function(){location.reload();}).catch(function(){box.classList.remove('is-busy');});
  }
  function render(box){
    box.className='cpb'+(applied?' is-applied':'');
    box.innerHTML='';
    var eyebrow=text(document.createElement('p'),applied?C.applied_eyebrow:C.eyebrow); eyebrow.className='cpb-eyebrow';
    var wrap=document.createElement('span'); wrap.className='cpb-code';
    wrap.appendChild(text(document.createElement('code'),CODE));
    var btn=document.createElement('button'); btn.type='button';
    var title=text(document.createElement('p'),applied?C.applied_title:C.title); title.className='cpb-title';
    var note=text(document.createElement('p'),applied?C.applied_note:C.note); note.className='cpb-note';
    if(applied){
      btn.innerHTML='&times;'; btn.title='Remove the promotion code'; btn.setAttribute('aria-label',btn.title);
      btn.addEventListener('click',function(){removePromo(box);});
    }else{
      text(btn,'Copy');
      btn.addEventListener('click',function(){
        var b=this,done=function(){text(b,'Copied');setTimeout(function(){text(b,'Copy');},1800);};
        var c=card(); if(c){fill(c.input);}
        if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(CODE).then(done,done);}else{done();}
      });
    }
    wrap.appendChild(btn);
    box.appendChild(eyebrow); box.appendChild(wrap); box.appendChild(title); box.appendChild(note);
  }
  function check(){
    if(!tag||checking||Date.now()-lastCheck<1500){return;}
    checking=true; lastCheck=Date.now();
    fetch('/index.php?rp=/api/v2/cart/'+encodeURIComponent(tag),{credentials:'same-origin',headers:{accept:'application/json'}})
      .then(function(r){return r.json();})
      .then(function(j){
        var promos=(j&&j.data&&j.data.promotions)||[];
        var now=promos.some(function(p){return String(p.code||p.promoCode||p.name||'').toUpperCase()===CODE.toUpperCase();});
        if(now!==applied){applied=now; var box=sr()&&sr().querySelector('.cpb'); if(box){render(box);} }
      }).catch(function(){}).then(function(){checking=false;});
  }
  function place(){
    var root=sr(); if(!root){return;}
    if(!root.querySelector('style[data-cpb]')){var st=document.createElement('style');st.setAttribute('data-cpb','1');st.textContent=CSS;root.appendChild(st);}
    var c=card(); if(!c||root.querySelector('.cpb')){check();return;}
    var box=document.createElement('div'); applied=false; render(box);
    c.card.parentElement.insertBefore(box,c.card);
    check();
  }
  place();
  var tries=0, iv=setInterval(function(){place(); if(++tries>40){clearInterval(iv);}},500);
  try{new MutationObserver(function(){place();}).observe(host,{childList:true,subtree:true});}catch(e){}
})();
</script>
HTML;
});
