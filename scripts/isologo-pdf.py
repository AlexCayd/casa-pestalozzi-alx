"""Genera views/admin/menu/_isologo.svg: el isologo de la casa (CASA sobre
PESTALOZZI en KudosKaps) como trazos, para la carta en PDF.

Por qué trazos y no la fuente: Dompdf no aplica el kerning GPOS de KudosKaps y,
con el .ttf convertido, el wordmark de la cabecera salía desarmado («CA SA PEST
A L O Z Z I»). Con el contorno de cada letra ya colocado, el PDF dibuja
exactamente lo que dibuja el navegador en el hero.

Mismas medidas que el hero (_hero.scss): tracking de 0.03 em, interlínea de
1.02 em y CASA centrada sobre PESTALOZZI. Sin color: el PDF lo tiñe al leerlo
(views/admin/menu/items-pdf.php, $logoEscalado). Sólo emite M, L, C y Z
absolutos, que es lo único que ese reescalado sabe multiplicar.

Uso (necesita fontTools, sólo para regenerar el archivo):
    python scripts/isologo-pdf.py
"""
import io
import os
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FUENTE = os.path.join(RAIZ, 'assets', 'fonts', 'KudosKapsOneNF.otf')
SALIDA = os.path.join(RAIZ, 'views', 'admin', 'menu', '_isologo.svg')

TRACKING = 0.03   # em, como .hero__isologo
INTERLINEA = 1.02  # em

fuente = TTFont(FUENTE)
glifos = fuente.getGlyphSet()
cmap = fuente.getBestCmap()
upm = fuente['head'].unitsPerEm
asc = fuente['hhea'].ascent


def ancho(texto):
    total = sum(fuente['hmtx'][cmap[ord(c)]][0] for c in texto)
    # El tracking va DETRÁS de cada letra salvo la última (el hero lo devuelve
    # con margin-right negativo para centrar de verdad).
    return total + TRACKING * upm * (len(texto) - 1)


def trazar(texto, x0, linea_base):
    pen = SVGPathPen(glifos, ntos=lambda n: ('%.1f' % n).rstrip('0').rstrip('.'))
    x = x0
    for c in texto:
        nombre = cmap[ord(c)]
        # y hacia abajo en SVG: se invierte el eje y se baja a la línea base.
        glifos[nombre].draw(TransformPen(pen, (1, 0, 0, -1, x, linea_base)))
        x += fuente['hmtx'][nombre][0] + TRACKING * upm
    return pen.getCommands()


arriba, abajo = 'CASA', 'PESTALOZZI'
w = ancho(abajo)
base1 = asc
base2 = asc + INTERLINEA * upm
alto = round(base2 + 40)  # el pie de las letras con un respiro

d = trazar(arriba, (w - ancho(arriba)) / 2, base1) + trazar(abajo, 0, base2)
svg = ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d">'
       '<path d="%s"/></svg>\n') % (round(w), alto, d)
io.open(SALIDA, 'w', encoding='utf-8', newline='\n').write(svg)
print('isologo:', SALIDA, round(w), 'x', alto, len(d), 'bytes de trazo')
