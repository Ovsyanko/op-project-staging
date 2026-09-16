(()=>{
  'use strict';
  const API = location.hostname.endsWith('github.io') ? 'https://op-co.ru/api/contact.php' : '/api/contact.php';
  const POLICY = location.hostname.endsWith('github.io') ? '/op-project-staging/policy/' : '/policy/';

  function isRequestForm(form){
    if(form.dataset.opContactReady) return false;
    const text=(form.closest('.request,.contact-card,.request-box,.request-section,.rf')?.textContent||form.parentElement?.textContent||'').toLowerCase();
    const ta=form.querySelector('textarea');
    return !!ta && (text.includes('сделать запрос') || (ta.placeholder||'').toLowerCase().includes('задач'));
  }

  function fieldBy(form, re, selector='input,textarea'){
    return [...form.querySelectorAll(selector)].find(el=>re.test(((el.placeholder||'')+' '+(el.name||'')).toLowerCase()));
  }

  function maskPhone(input){
    if(!input) return;
    const fmt=()=>{
      let d=input.value.replace(/\D/g,'');
      if(d.startsWith('8')) d='7'+d.slice(1);
      if(!d.startsWith('7')) d='7'+d;
      d=d.slice(0,11);
      let s='+7';
      if(d.length>1) s+=' ('+d.slice(1,4);
      if(d.length>=4) s+=')';
      if(d.length>4) s+=' '+d.slice(4,7);
      if(d.length>7) s+='-'+d.slice(7,9);
      if(d.length>9) s+='-'+d.slice(9,11);
      input.value=s;
    };
    input.addEventListener('input',fmt);
    input.addEventListener('focus',()=>{if(!input.value) input.value='+7';});
    input.addEventListener('blur',()=>{if(input.value==='+7') input.value='';});
  }

  function setup(form){
    if(!isRequestForm(form)) return;
    form.dataset.opContactReady='1';
    form.setAttribute('novalidate','');
    form.removeAttribute('onsubmit');

    const name=fieldBy(form,/имя|name/);
    const phone=fieldBy(form,/\+7|телефон|phone/);
    const email=fieldBy(form,/e-mail|email/);
    const message=fieldBy(form,/задач|message|сообщ/,'textarea');
    if(!name||!email||!message) return;

    Object.assign(name,{name:'name',placeholder:'Имя*',required:true,maxLength:100,autocomplete:'name'});
    if(phone){Object.assign(phone,{name:'phone',placeholder:'+7',type:'tel',maxLength:18,autocomplete:'tel'});phone.setAttribute('inputmode','tel');maskPhone(phone);}
    Object.assign(email,{name:'email',placeholder:'E-mail*',required:true,type:'email',maxLength:160,autocomplete:'email'});
    Object.assign(message,{name:'message',placeholder:'Опишите задачу*',required:true,maxLength:1000});

    const checks=[...form.querySelectorAll('label')];
    let newsletterLabel=checks.find(l=>/новост|рассыл/i.test(l.textContent));
    let consentLabel=checks.find(l=>/обработк|персональн/i.test(l.textContent));
    const boxInputs=[...form.querySelectorAll('input[type="checkbox"]')];
    if(!newsletterLabel && boxInputs[0]) newsletterLabel=boxInputs[0].closest('label');
    if(!consentLabel && boxInputs[1]) consentLabel=boxInputs[1].closest('label');
    if(newsletterLabel){const cb=newsletterLabel.querySelector('input');if(cb){cb.name='newsletter';cb.value='1';}}
    if(consentLabel){
      const cb=consentLabel.querySelector('input');
      if(cb){cb.name='consent';cb.value='1';cb.required=true;}
      consentLabel.innerHTML='';
      if(cb) consentLabel.append(cb,document.createTextNode(' '));
      const span=document.createElement('span');
      span.innerHTML=`Согласен на <a href="${POLICY}" target="_blank" rel="noopener">обработку персональных данных</a>*`;
      consentLabel.append(span);
    } else {
      consentLabel=document.createElement('label'); consentLabel.className='check';
      consentLabel.innerHTML=`<input type="checkbox" name="consent" value="1" required> <span>Согласен на <a href="${POLICY}" target="_blank" rel="noopener">обработку персональных данных</a>*</span>`;
      form.append(consentLabel);
    }

    let hp=form.querySelector('[name="company_website"]');
    if(!hp){hp=document.createElement('input');hp.type='text';hp.name='company_website';hp.tabIndex=-1;hp.autocomplete='off';hp.setAttribute('aria-hidden','true');hp.style.cssText='position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;opacity:0!important';form.append(hp);}

    // Reuse the form's existing Send button regardless of whether the source markup
    // declared it as type="button". Some reconstructed pages use type="button";
    // the old selector ignored those buttons and appended a second submit button.
    let submit=[...form.querySelectorAll('button,.form-btn')].find(el=>/отправить/i.test((el.textContent||'').trim()))
      || form.querySelector('button[type="submit"],button[type="button"],button:not([type]),.form-btn');
    if(submit && submit.tagName==='A'){
      const b=document.createElement('button'); b.type='submit'; b.className=submit.className; b.textContent='Отправить'; submit.replaceWith(b); submit=b;
    }
    if(!submit){submit=document.createElement('button');submit.type='submit';submit.textContent='Отправить';form.append(submit);}
    if(submit.tagName==='BUTTON') submit.type='submit';

    // Defensive cleanup in case a page contains more than one legacy Send control.
    [...form.querySelectorAll('button,.form-btn')].forEach(el=>{
      if(el!==submit && /отправить/i.test((el.textContent||'').trim())) el.remove();
    });

    [...form.querySelectorAll('small')].forEach(s=>{if(/staging|не отправляет|электронной почте/i.test(s.textContent))s.remove();});
    let status=document.createElement('div');status.className='op-form-status';status.setAttribute('role','status');status.setAttribute('aria-live','polite');form.append(status);

    form.addEventListener('submit',async e=>{
      e.preventDefault();
      status.textContent='';status.className='op-form-status';
      if(!form.reportValidity()) return;
      const fd=new FormData(form);
      fd.set('page_url',location.href);
      fd.set('page_title',document.title);
      submit.disabled=true; const old=submit.textContent; submit.textContent='Отправляем…';
      try{
        const r=await fetch(API,{method:'POST',body:fd,headers:{'Accept':'application/json'}});
        const data=await r.json().catch(()=>({}));
        if(!r.ok||!data.ok) throw new Error(data.message||'Не удалось отправить заявку');
        status.textContent='Спасибо! Заявка отправлена. Мы свяжемся с Вами.';status.classList.add('ok');
        form.reset(); if(phone) phone.value='';
      }catch(err){
        status.textContent=(err&&err.message)||'Ошибка отправки. Попробуйте ещё раз или напишите на sales@op-project.ru';status.classList.add('error');
      }finally{submit.disabled=false;submit.textContent=old;}
    });
  }

  const style=document.createElement('style');style.textContent=`
    .op-form-status{font-size:13px;line-height:1.4;margin-top:4px;min-height:18px}.op-form-status.ok{color:#18794e}.op-form-status.error{color:#b42318}
    form[data-op-contact-ready="1"] label a{text-decoration:underline}form[data-op-contact-ready="1"] button:disabled{opacity:.65;cursor:wait}
  `;document.head.append(style);

  function scan(root=document){root.querySelectorAll?.('form').forEach(setup);}
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',()=>scan()); else scan();
  new MutationObserver(ms=>ms.forEach(m=>m.addedNodes.forEach(n=>{if(n.nodeType===1){if(n.matches?.('form'))setup(n);scan(n);}}))).observe(document.documentElement,{childList:true,subtree:true});
})();
