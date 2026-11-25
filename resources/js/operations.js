const root=document.querySelector('.o-workspace');
if(root){
    root.querySelectorAll('form[method="post"]').forEach(form=>form.addEventListener('submit',()=>{form.setAttribute('aria-busy','true');form.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=true);}));
}
