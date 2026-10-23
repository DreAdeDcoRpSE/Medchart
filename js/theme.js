/* MedChart screen theme. Preference is browser-local, no account/database changes. */
(function () {
  'use strict';
  var key = 'medchart.theme';
  var valid = {auto: true, light: true, dark: true};
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function preference() { try { var v=localStorage.getItem(key); return valid[v] ? v : 'auto'; } catch(e) { return 'auto'; } }
  function apply() {
    var pref=preference();
    var resolved=pref === 'auto' ? (media && media.matches ? 'dark' : 'light') : pref;
    document.documentElement.setAttribute('data-medchart-theme', resolved);
    document.documentElement.style.colorScheme=resolved;
    var picker=document.getElementById('medchart-theme-select');
    if (picker) picker.value=pref;
  }
  apply();
  if (media) { if (media.addEventListener) media.addEventListener('change', apply); else if(media.addListener) media.addListener(apply); }
  document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('medchart-theme-control')) return;
    var control=document.createElement('div');
    control.id='medchart-theme-control';
    control.className='medchart-theme-control';
    var label = document.createElement("label");
    label.setAttribute('for','medchart-theme-select');
    label.textContent='Theme';
    var picker=document.createElement('select');
    picker.id='medchart-theme-select';
    picker.setAttribute('aria-label','Appearance theme');
    [['auto','Auto'],['light','Light'],['dark','Dark']].forEach(function(item){var option=document.createElement('option');option.value=item[0];option.textContent=item[1];picker.appendChild(option);});
    picker.addEventListener('change',function(){try{localStorage.setItem(key,picker.value);}catch(e){}apply();});
    control.appendChild(label);control.appendChild(picker);
    var header=document.querySelector('.bodywrap > header');
    if(header) header.insertBefore(control,header.firstChild);
    else {var login=document.querySelector('.login-container');if(login)login.insertBefore(control,login.firstChild);else {var main=document.querySelector('main');if(main)main.insertBefore(control,main.firstChild);else document.body.insertBefore(control,document.body.firstChild);}}
    apply();
  });
})();
