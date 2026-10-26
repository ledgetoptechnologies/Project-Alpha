const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/invoice-autopay-preview.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
function setup() {
  const button = { textContent: 'Save & Send' }, text = {}, requests = [], pending = [], fields = { csrf: { value:'synthetic' }, client_id:{value:'4'} };
  const form = { elements:{namedItem:name=>fields[name]}, querySelector:()=>button, addEventListener:(name,fn)=>form[name]=fn };
  const panel = {dataset:{invoiceId:'0'},closest:()=>form,querySelector:()=>text};
  vm.runInNewContext(source,{document:{readyState:'complete',querySelectorAll:()=>[panel]},FormData:Map,fetch:(url,options)=>{requests.push({url,options});return new Promise(resolve=>pending.push(resolve));}});
  const respond=(index,data)=>pending[index]({ok:true,json:async()=>({success:true,enabled:true,...data})});
  return {button,text,form,panel,requests,respond};
}
test('authorized client sees masked scope and review action, preserving submitted action',async()=>{
  const x=setup();x.respond(0,{eligible:true,method:'Bank account ending 1234',scope:'Future invoices for this client account'});await settle();
  assert.equal(x.button.textContent,'Save & review AutoPay');assert.match(x.text.textContent,/ending 1234/);assert.match(x.text.textContent,/does not charge/);assert.equal(x.button.value,undefined);
  assert.equal(x.requests[0].options.body.get('csrf'),'synthetic');assert.equal(x.requests[0].options.body.has('project_id'),false);
});
test('stale client response cannot overwrite newer project billing preview',async()=>{
  const x=setup();x.form.change({target:{name:'project_id'}});x.respond(1,{eligible:false,state:'separate_collection',project_billing:true});await settle();x.respond(0,{eligible:true,method:'stale',scope:'stale'});await settle();
  assert.equal(x.button.textContent,'Finalize for Project Billing');assert.doesNotMatch(x.text.textContent,/stale/);
});
test('untrusted display content uses text only and production-disabled response hides panel',async()=>{
  const x=setup();x.respond(0,{eligible:true,method:'<img onerror=x>',scope:'agreement'});await settle();assert.equal(x.text.innerHTML,undefined);
  x.form.change({target:{name:'client_id'}});x.respond(1,{enabled:false});await settle();assert.equal(x.panel.hidden,true);
});
