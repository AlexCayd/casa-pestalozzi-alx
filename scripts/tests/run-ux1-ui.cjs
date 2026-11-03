const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');

(async () => {
    const dom = new JSDOM('<button id="origen" data-admin-modal-open="a">Abrir</button><div id="a" data-admin-modal hidden><section data-admin-modal-dialog><button data-admin-modal-open="b">Siguiente</button></section></div><div id="b" data-admin-modal hidden><section data-admin-modal-dialog><button data-admin-modal-close>Cerrar</button></section></div>', {runScripts:'outside-only',pretendToBeVisual:true});
    const w=dom.window,d=w.document;
    w.matchMedia=()=>({matches:false,addEventListener(){}});
    await new Promise(resolve=>w.addEventListener('load',resolve));
    w.eval(fs.readFileSync('src/js/admin/admin.js','utf8'));
    d.dispatchEvent(new w.Event('DOMContentLoaded'));
    d.querySelector('#origen').click();
    d.querySelector('#a button').click();
    await new Promise(resolve=>setTimeout(resolve,40));
    assert.equal(d.querySelector('#a').hidden,true,'El primer diálogo se limpia al encadenar');
    assert.equal(d.querySelector('#b').hidden,false);
    d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
    await new Promise(resolve=>setTimeout(resolve,250));
    assert.equal(d.querySelector('#b').hidden,true);
    assert.equal(d.body.style.overflow,'');
    assert.equal(d.activeElement.id,'origen','Regresa al disparador visible de origen');
    dom.window.close();
    for (const preview of [false,true]) {
        const draft=new JSDOM('<form id="capacidades"><input name="accion" value="configurar"></form><form id="receta" '+(preview?'data-m2-pendiente':'')+'><input name="procedencia" value="Pendiente"><div data-m2-builder><div data-m2-rows></div><template data-m2-template></template><p data-m2-status></p></div></form>',{runScripts:'outside-only'});
        let confirmation=null;
        draft.window.ConfirmationModal={get:()=>({open:options=>{confirmation=options;}})};
        draft.window.eval(fs.readFileSync('src/js/admin/recetas/articulos.js','utf8'));
        const input=draft.window.document.querySelector('#receta input');
        if(!preview)input.value='Modificado';
        const submit=new draft.window.Event('submit',{cancelable:true});
        draft.window.document.querySelector('#capacidades').dispatchEvent(submit);
        assert.equal(submit.defaultPrevented,true,'No descartar borrador ni previsión sin consentimiento');
        assert.equal(confirmation.secondaryLabel,'Seguir editando');
        assert.equal(input.value,preview?'Pendiente':'Modificado','Cancelar no cambia los datos');
        draft.window.close();
    }

    const source=fs.readFileSync('src/js/admin/menu/variantes.js','utf8');
    const context={datos:{base:[{tipo:'ingrediente',ref_id:1,cantidad:'10.000'},{tipo:'ingrediente',ref_id:2,cantidad:'100.000'}]},ingredientes:{1:{costo:'0.2500'},2:{costo:'0.0150'},3:{costo:'0.0300'}},subrecetas:{}};
    vm.createContext(context);
    vm.runInContext(source.slice(source.indexOf('    var base = datos.base'),source.indexOf('    function componente('))+source.slice(source.indexOf('    function explotarComponente('),source.indexOf('    // ── Frases de un ajuste')),context);
    context.opciones=[{ajustes:[{accion:'sustituir',tipo:'ingrediente',ref_id:2,cantidad:100,nuevo_tipo:'ingrediente',nuevo_ref_id:3,nueva_cantidad:100}]}];
    assert.equal(vm.runInContext('costo(explotar([]))',context),4);
    assert.equal(vm.runInContext('costo(explotar(opciones))',context),5.5,'Café 10g y avena 100ml: contrato de consumo M2');
    context.ingredientes[3].costo=null;
    assert.equal(vm.runInContext('costo(explotar(opciones))',context),null,'Desconocido no se vuelve cero');
    context.ingredientes[3].costo='0.0000';
    assert.equal(vm.runInContext('costo(explotar(opciones))',context),2.5,'Cero explícito sí contribuye cero');
    const motor=JSON.parse(require('node:child_process').execFileSync('php',['scripts/tests/fixture-ux1-costos.php'],{encoding:'utf8'}));
    for (const [i,cost] of ['0.0300',null,'0.0000'].entries()) {
        context.ingredientes[3].costo=cost;
        assert.equal(vm.runInContext('costo(explotar(opciones))',context),motor[i]===null?null:Number(motor[i]),'Preview coincide con motor M2');
    }
    console.log('UX1 UI: encadenamiento, Escape, foco de retorno y costo conocido/desconocido/cero OK.');
})().catch(error=>{console.error(error);process.exitCode=1;});
