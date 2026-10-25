/* Profile fields only. Each field displays its own save state. */
(function(){'use strict';
 const ids=['name','dob','notes'];
 const fields=ids.map(id=>document.getElementById(id));
 const button=document.getElementById('profile-save');
 const bottomButton=document.getElementById('profile-save-bottom');
 const saveButtons=[button,bottomButton].filter(Boolean);
 if(!button||fields.some(x=>!x))return;
 let baseline=fields.map(x=>x.value),saving=false;
 const changed=()=>fields.some((x,i)=>x.value!==baseline[i]);
 const statuses=ids.map(id=>document.getElementById('profile-status-'+id));
 function show(i,message,state){const el=statuses[i];if(!el)return;el.textContent=message;el.dataset.state=state||'';}
 function update(){fields.forEach((field,i)=>{if(field.value!==baseline[i])show(i,'Unsaved change — click either Save button.','dirty');else show(i,'','');});saveButtons.forEach(btn=>btn.classList.toggle('profile-dirty',changed()));}
 fields.forEach(field=>{field.addEventListener('input',update);field.addEventListener('change',update);});
 window.addEventListener('beforeunload',e=>{if(changed()&&!saving){e.preventDefault();e.returnValue='';}});
 document.addEventListener('click',e=>{if(!e.target.closest('.medchart-menu'))document.querySelectorAll('.medchart-menu[open]').forEach(x=>x.open=false);});
 window.medchartProfileSaveStarted=function(){saving=true;return fields.map(x=>x.value);};
 window.medchartProfileSaved=function(savedValues){saving=false;baseline=savedValues.slice();fields.forEach((field,i)=>{if(field.value===baseline[i])show(i,'Saved successfully.','saved');else show(i,'Changed while saving — click Save again.','dirty');});saveButtons.forEach(btn=>btn.classList.toggle('profile-dirty',changed()));};
 window.medchartProfileSaveError=function(){saving=false;fields.forEach((field,i)=>{if(field.value!==baseline[i])show(i,'Save failed — your change is not saved.','error');});saveButtons.forEach(btn=>btn.classList.toggle('profile-dirty',changed()));};
})();
