import React from 'react';
import { http } from '@pterodactyl/sdk';
const h = React.createElement;
const API = '/api/client/extensions/discord-event-notifier/preferences';
const options = ['started','stopped','restarted','crashed','recovered','provision','install','reinstall','backup','failed'];
const title = x => x === 'restarted' ? 'Restart detected' : x[0].toUpperCase()+x.slice(1);
const panel = {background:'#202a36', color:'#e5e7eb', padding:20, borderRadius:8, marginBottom:16};
const field = {padding:9,borderRadius:6,background:'#111b27',color:'#f9fafb',border:'1px solid #596474',width:'100%',boxSizing:'border-box'};
const button = {padding:'10px 18px',background:'#2563eb',color:'white',border:0,borderRadius:6,cursor:'pointer',marginRight:10,marginTop:10};
export default function Preferences() {
  const [data,setData] = React.useState(null), [secret,setSecret] = React.useState(''),
    [serverSecrets,setServerSecrets] = React.useState({}), [removeSecrets,setRemoveSecrets] = React.useState({}),
    [removeMain,setRemoveMain] = React.useState(false), [message,setMessage] = React.useState(''),[busy,setBusy] = React.useState(false);
  React.useEffect(()=>{ http.get(API).then(r=>setData(r.data)).catch(e=>setMessage(e.response?.data?.message || 'Could not load settings.')); },[]);
  const save = async () => {
    setBusy(true);setMessage('');
    try {
      const servers = {};
      for (const s of data.servers) servers[s.uuid] = {enabled:s.enabled, ...(serverSecrets[s.uuid]?{webhook_url:serverSecrets[s.uuid]}:{}), ...(removeSecrets[s.uuid]?{remove_override:true}:{})};
      const result = await http.put(API, {enabled:data.enabled,events:data.events,servers,...(secret?{webhook_url:secret}:{}),remove_webhook:removeMain});
      setData(result.data);setSecret('');setServerSecrets({});setRemoveSecrets({});setRemoveMain(false);setMessage('Settings saved successfully.');
    } catch(e) {setMessage(e.response?.data?.message || 'Saving failed.');} finally {setBusy(false);}
  };
  const test = async uuid => {setBusy(true);setMessage('');try {await http.post(API+'/test',uuid?{server_uuid:uuid}:{});setMessage('Test message sent.');} catch(e){setMessage(e.response?.data?.message || 'Test failed.');}finally{setBusy(false);} };
  const toggle=(label,checked,onChange)=>h('label',{style:{display:'flex',alignItems:'center',gap:9,margin:'8px 0'}},h('input',{type:'checkbox',checked:!!checked,onChange:e=>onChange(e.target.checked)}),label);
  const input=(placeholder,value,change)=>h('input',{type:'password',placeholder,value,onChange:e=>change(e.target.value),autoComplete:'off',style:field});
  if (!data) return h('div',{style:panel},message||'Loading Discord notifications...');
  return h('div',{style:{maxWidth:820,margin:'30px auto',padding:'0 16px'}},
    h('h1',{style:{fontSize:26,marginBottom:8}},'Discord Notifications'),
    h('p',{style:{opacity:.8}},'Configure your Discord webhook for servers you own or have been granted Discord Notifications permission to manage.'),
    message && h('p',{role:'status',style:{padding:12,border:'1px solid #6b7280',borderRadius:6}},message),
    h('section',{style:panel},h('h2',null,'Personal webhook'),
      toggle('Enable my notifications',data.enabled,v=>setData({...data,enabled:v})),
      h('p',null,data.has_webhook?'A webhook is saved. Enter a new URL to replace it.':'No webhook saved yet.'),
      input('https://discord.com/api/webhooks/...',secret,setSecret),
      toggle('Remove saved webhook',removeMain,setRemoveMain),
      h('button',{style:button,disabled:busy,onClick:()=>test(null)},'Test personal webhook')),
    h('section',{style:panel},h('h2',null,'Events'),...options.map(ev=>toggle(title(ev),data.events[ev]!==false,v=>setData({...data,events:{...data.events,[ev]:v}})))),
    h('section',{style:panel},h('h2',null,'My servers'),
      data.servers.length===0?h('p',null,'No authorized servers. Ask the server owner to enable Discord Notifications → Manage in your subuser permissions.'):data.servers.map(s=>h('div',{key:s.uuid,style:{borderTop:'1px solid #465366',padding:'14px 0'}},
        h('strong',null,s.name),h('span',{style:{opacity:.75,marginLeft:8,fontSize:12}},s.role === 'subuser' ? '(Subuser)' : '(Owner)'),h('div',{style:{fontSize:12,opacity:.7}},s.uuid),
        toggle('Send notifications',s.enabled,v=>setData({...data,servers:data.servers.map(x=>x.uuid===s.uuid?{...x,enabled:v}:x)})),
        h('p',null,s.has_override?'A server-specific webhook is saved.':'Uses your personal webhook by default.'),
        input('Optional per-server Discord webhook URL',serverSecrets[s.uuid]||'',v=>setServerSecrets({...serverSecrets,[s.uuid]:v})),
        toggle('Remove server-specific webhook',!!removeSecrets[s.uuid],v=>setRemoveSecrets({...removeSecrets,[s.uuid]:v})),
        h('button',{style:button,disabled:busy,onClick:()=>test(s.uuid)},'Test this server webhook')))),
    h('button',{style:{...button,background:'#16a34a'},disabled:busy,onClick:save},busy?'Saving...':'Save settings'));
}
