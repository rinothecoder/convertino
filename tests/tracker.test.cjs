const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(require('path').join(__dirname, '../assets/tracker.js'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
async function settle() { for (let i=0;i<12;i++) await tick(); }
function setup(options={}) {
  const requests=[], listeners={}, playerListeners={};
  let sequence=0, failEvent=true, expireNext=false;
  const player={closest:()=>({id:'fluent_player_116_1'}),addEventListener:(key,cb)=>playerListeners[key]=cb};
  class Element { closest(selector) { return selector === '#buy' ? {} : null; } }
  const window={ConvertinoConfig:{endpoint:'https://example.test/wp-json/convertino/v1/',page:13,proof:'signed',buttons:[{key:'buy-key',selector:'#buy'}]}};
  const ctx={window,Element,document:{referrer:'https://chatgpt.com/',documentElement:{},querySelectorAll:()=>[player],addEventListener:(key,cb)=>listeners[key]=cb},navigator:{...options},location:{search:'?utm_source=ChatGPT'},crypto:{randomUUID:()=>`${String(++sequence).padStart(8,'0')}-0000-0000-0000-000000000000`},URLSearchParams,Uint8Array,MutationObserver:class{observe(){}},fetch:async(url,config)=>{requests.push({url,body:JSON.parse(config.body)});if(url.endsWith('event')&&expireNext){expireNext=false;return {ok:false,status:409};}if(url.endsWith('event')&&failEvent){failEvent=false;return {ok:false,status:503};} return {ok:true,status:200};}};
  vm.runInNewContext(source,ctx);
  return {window,requests,listeners,playerListeners,expire:()=>{expireNext=true;},click:()=>listeners.click({target:new Element()})};
}
(async()=>{
  const x=setup();
  x.click(); await settle(); assert.equal(x.requests.length,0,'no tracking before consent');
  x.window.Convertino.setConsent(true); x.click(); await settle();
  assert.equal(x.requests[0].body.source,'ChatGPT');
  assert.equal(x.requests[0].body.referrer,'https://chatgpt.com/');
  assert.equal(x.requests[0].url.endsWith('start'),true,'visit precedes click');
  const clicks=x.requests.filter(r=>r.body.type==='click');
  assert.equal(clicks.length,2,'failed event is retried');
  assert.equal(clicks[0].body.id,clicks[1].body.id,'retry preserves event identity');
  x.click(); await settle();
  const latest=x.requests.filter(r=>r.body.type==='click');
  assert.notEqual(latest[latest.length-1].body.id,clicks[0].body.id,'separate click counts separately');
  x.playerListeners.play();x.playerListeners.play();await settle();
  assert.equal(x.requests.filter(r=>r.body.type==='video_play').length,1,'replays deduplicate per visit/video');
  x.playerListeners.ended();await settle();
  assert.equal(x.requests.filter(r=>r.body.type==='video_end').length,1);
  const oldView=x.requests[0].body.id;x.expire();x.click();await settle();
  const starts=x.requests.filter(r=>r.url.endsWith('start'));assert.equal(starts.length,2,'expired visit starts again');assert.notEqual(starts[1].body.id,oldView,'new visit has new exposure ID');
  x.window.Convertino.setConsent(false);await settle();
  assert.equal(x.requests.at(-1).url.endsWith('revoke'),true);
  const n=x.requests.length;x.click();x.playerListeners.play();await settle();assert.equal(x.requests.length,n,'withdrawal stops all events');
  const gpc=setup({globalPrivacyControl:true});gpc.window.Convertino.setConsent(true);await settle();assert.equal(gpc.requests.length,0,'GPC respected');
  const bot=setup({webdriver:true});bot.window.Convertino.setConsent(true);await settle();assert.equal(bot.requests.length,0,'browser automation excluded');
  console.log('PASS: consent, attribution payload, ordering, retries, separate clicks, video deduplication, withdrawal, GPC, bot exclusion');
})().catch(e=>{console.error(e);process.exitCode=1;});
