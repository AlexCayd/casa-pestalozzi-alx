const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let now = 0;
const timers = new Map();
let seq = 0;
const window = { setInterval(fn) { timers.set(++seq, fn); return seq; }, clearInterval(id) { timers.delete(id); }, addEventListener() {}, removeEventListener() {} };
vm.runInNewContext(fs.readFileSync('src/js/components/reservation-resend.js', 'utf8'), {window, Date: {now: () => now, parse: Date.parse}, Number, String, Boolean, Promise, Object, Error});
function element() { return {textContent:'',disabled:false,setAttribute(){}}; }
const button=element(), remaining=element(), announcement=element();
const control = window.ReservationResend.create({button,remaining,announcement});
function advance(seconds) { now += seconds*1000; for (const fn of [...timers.values()]) fn(); }
(async () => {
  assert.equal(button.disabled,true);
  control.update({remaining_resends:2,retry_after_seconds:60,next_resend_at:'2037-01-01',can_send:false});
  advance(1); assert.equal(button.textContent,'Reenviar código en 00:59');
  assert.equal(remaining.textContent,'2 reenvíos disponibles');
  const status = announcement.textContent;
  advance(1); assert.equal(announcement.textContent,status,'contador no anuncia cada segundo');
  advance(58); assert.equal(button.disabled,false);
  let calls=0, finish;
  const promise=control.run(() => { calls++; return new Promise(resolve=>{finish=resolve;}); });
  control.run(() => {calls++;});
  await Promise.resolve(); assert.equal(calls,1,'doble clic');
  finish({remaining_resends:1,retry_after_seconds:60,next_resend_at:'2037-01-01'}); await promise;
  assert.equal(remaining.textContent,'1 reenvío disponible');
  control.update({remaining_resends:0,retry_after_seconds:0});
  assert.equal(button.disabled,true); assert.match(remaining.textContent,/No quedan/);
  control.update({remaining_resends:2,retry_after_seconds:0,next_resend_at:null,can_send:false});
  assert.equal(button.disabled,true,'resultado incierto bloqueado por backend');
  control.update(null); assert.equal(button.disabled,true,'respuesta incompleta no reinicia cupo');
  control.reset(); assert.equal(timers.size,0);
  assert.equal(window.ReservationResend.needsVerification({codigo:'OTP_ENVIO_FALLIDO'}),true);
  assert.match(window.ReservationResend.message({ok:true,codigo:'CODIGO_CONFIRMACION_ENVIADO',channel:'whatsapp'}),/WhatsApp/);
  console.log('UX reenvío:countdown,cupo backend,doble clic,fallo y limpieza OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
