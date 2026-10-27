const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/invoice-billing-recipients.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));
function node() {
  return {children:[],style:{},textContent:'',listeners:{},append(...children){this.children.push(...children);},replaceChildren(...children){this.children=children;},addEventListener(name,fn){this.listeners[name]=fn;}};
}
function setup(options={}) {
  const summary=node(), candidates=node(), custom=node(), mode={value:options.mode || 'inherit'}, copy={checked:false};
  const fields={csrf:{value:'synthetic'},client_id:{value:options.client || '4'},project_id:{value:''},recipient_presentation_mode:{value:'named'}};
  const form={querySelector(selector){const match=selector.match(/^\[name="(.*)"\]$/);return match?fields[match[1]]:null;},addEventListener(name,fn){this[name]=fn;}};
  const panel={dataset:{invoiceId:'0',selectedIds:'[]',projectId:'0'},closest:()=>form,querySelector(selector){return {'[name="billing_recipient_mode"]':mode,'[data-billing-custom]':custom,'[data-billing-candidates]':candidates,'[data-billing-summary]':summary,'[name="billing_copy_working_contact"]':copy}[selector];}};
  const requests=[],pending=[],timers=new Map(); let nextTimer=0, initializer=null;
  class Body extends Map {append(key,value){const prior=this.get(key)||[];prior.push(value);this.set(key,prior);}}
  vm.runInNewContext(source,{document:{querySelectorAll:()=>[panel],createElement:node,addEventListener(){}},FormData:Body,
    window:options.softNavigation?{ProjectAlpha:{registerPage(routes,fn){initializer=fn;}}}:undefined,
    fetch:(url,options)=>{requests.push({url,options});return new Promise(resolve=>pending.push(resolve));},
    setTimeout:fn=>{timers.set(++nextTimer,fn);return nextTimer;},clearTimeout:id=>timers.delete(id)});
  function flush(){const jobs=[...timers.values()];timers.clear();jobs.forEach(fn=>fn());}
  function respond(index,data={}){pending[index]({json:async()=>({success:true,source:'organization',working_contact:'Alex',organization_name:'Acme',recipients:[{id:5,name:'Pat',email:'pat@example.test'}],candidates:[{id:5,name:'Pat',email:'pat@example.test'}],...data})});}
  return {panel,form,fields,mode,copy,custom,candidates,summary,requests,flush,respond,initialize:()=>initializer()};
}
test('organization routing shows working contact separately and sends only chosen accounting recipients',async()=>{
  const x=setup();x.respond(0);await settle();
  assert.equal(x.requests[0].options.body.get('client_id'),'4');
  assert.equal(x.requests[0].options.body.get('billing_recipient_mode'),'inherit');
  assert.equal(x.custom.hidden,true);
  assert.match(x.summary.children[0].textContent,/Working contact: Alex/);
  assert.equal(x.summary.children[2].textContent,'Sends to: Pat <pat@example.test>');
});
test('custom choices submit saved contact identifiers and explicit working-contact copy',async()=>{
  const x=setup({mode:'custom'});x.respond(0);await settle();
  const checkbox=x.candidates.children[0].children[0];checkbox.checked=true;checkbox.listeners.change();x.copy.checked=true;x.flush();
  assert.deepEqual(x.requests[1].options.body.get('billing_recipient_ids[]'),['5']);
  assert.equal(x.requests[1].options.body.get('billing_copy_working_contact'),'1');
});
test('older response cannot replace recipients after a working-contact change',async()=>{
  const x=setup();x.fields.client_id.value='9';x.form.change();
  x.respond(0,{working_contact:'stale'});await settle();assert.equal(x.summary.children.length,0);
  x.flush();x.respond(1,{working_contact:'Current'});await settle();assert.match(x.summary.children[0].textContent,/Current/);
});
test('general recipient mode disables private delivery choices and makes no request',()=>{
  const x=setup();x.fields.recipient_presentation_mode.value='general';x.form.change();x.flush();
  assert.equal(x.panel.hidden,true);assert.equal(x.panel.disabled,true);assert.equal(x.requests.length,1);
});
test('untrusted names are text and missing recipients show a review reason',async()=>{
  const x=setup();x.respond(0,{working_contact:'<img onerror=x>',recipients:[],reason:'Select accounting contacts.'});await settle();
  assert.match(x.summary.children[0].textContent,/<img onerror=x>/);assert.equal(x.summary.children[0].innerHTML,undefined);
  assert.equal(x.summary.children[2].textContent,'Sends to: No deliverable recipients');assert.equal(x.summary.children[3].textContent,'Select accounting contacts.');
});
test('finalize requires a current deliverable preview while saving a draft stays available',async()=>{
  const x=setup();let prevented=false;
  const event={submitter:{id:'finalizeAndSendInvoice'},preventDefault(){prevented=true;}};
  x.form.submit(event);assert.equal(prevented,true);
  x.respond(0);await settle();prevented=false;x.form.submit(event);assert.equal(prevented,false);
  x.form.change();x.form.submit(event);assert.equal(prevented,true);
  prevented=false;x.form.submit({submitter:{id:'saveDraft'},preventDefault(){prevented=true;}});assert.equal(prevented,false);
});
test('soft navigation registration initializes a panel once without duplicate previews',()=>{
  const x=setup({softNavigation:true});assert.equal(x.requests.length,0);
  x.initialize();x.initialize();assert.equal(x.requests.length,1);
  x.fields.client_id.value='8';x.form.change();x.flush();assert.equal(x.requests.length,2);
});
