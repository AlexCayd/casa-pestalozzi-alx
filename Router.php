<?php

namespace MVC;

class Router
{
    public array $getRoutes = [];
    public array $postRoutes = [];
    public array $deleteRoutes = [];

    /**
     * Respuesta para una URL que no está en ninguno de los tres mapas.
     *
     * Va como callable registrable y no incrustado aquí porque este archivo es
     * el framework: no menciona `Controllers\` ni una vez, y meterle la
     * dependencia sería la única en esa dirección. Se registra en
     * public/index.php junto al resto de rutas, que es la convención.
     */
    private $notFound = null;

    public function notFound($fn)
    {
        $this->notFound = $fn;
    }

    public function get($url, $fn)
    {
        $this->getRoutes[$url] = $fn;
    }

    public function post($url, $fn)
    {
        $this->postRoutes[$url] = $fn;
    }

    public function delete($url, $fn)
    {
        $this->deleteRoutes[$url] = $fn;
    }

    public function comprobarRutas()
    {

        $url_actual = $_SERVER['PATH_INFO'] ?? '/';
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $fn = $this->getRoutes[$url_actual] ?? null;
        } elseif ($method === 'DELETE') {
            $fn = $this->deleteRoutes[$url_actual] ?? null;
        } else {
            $fn = $this->postRoutes[$url_actual] ?? null;
        }

        if ( $fn ) {
            call_user_func($fn, $this);
            return;
        }

        if ($this->notFound) {
            call_user_func($this->notFound, $this, $url_actual);
            return;
        }

        // Respaldo por si alguien instancia el Router sin registrar el suyo.
        // El echo pelado que había aquí contestaba 200 OK, sin HTML y sin
        // charset: los acentos salían como bytes crudos.
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Página no encontrada';
    }

    public function render($view, $datos = [])
    {
        foreach ($datos as $key => $value) {
            $$key = $value; 
        }

        ob_start(); 

        include_once __DIR__ . "/views/$view.php";

        $contenido = ob_get_clean(); // Limpia el Buffer

        include_once __DIR__ . '/views/layout.php';
    }
}
