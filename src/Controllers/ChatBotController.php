<?php

require_once __DIR__ . '/../Services/ChatBotService.php';

/**
 * Guarda la conversación (sesiones_chat / mensajes_chat) y delega la respuesta al servicio.
 */
class ChatBotController
{
    private const HORAS_SESION = 12;
    private const MAX_MENSAJE = 300;
    private const MAX_HISTORIAL = 30;

    private PDO $db;
    private int $idUsuario;
    private ChatBotService $servicio;

    public function __construct(PDO $db, int $idUsuario)
    {
        $this->db = $db;
        $this->idUsuario = $idUsuario;
        $this->servicio = new ChatBotService($db, $idUsuario);
    }

    /** Devuelve historial reciente + insignia. Si no hay historial, saluda. */
    public function iniciar(): array
    {
        $idSesion = $this->obtenerSesion();
        $historial = $this->historial($idSesion);
        $botones = [];
        if (!$historial) {
            $saludo = $this->servicio->saludoInicial();
            $this->guardar($idSesion, 'Bot', $saludo['texto']);
            $historial = $this->historial($idSesion);
            $botones = $saludo['botones'];
        } else {
            $botones = ['Mis fechas', 'Actividades', 'Cursos', 'Mi progreso', 'Historial'];
        }
        return ['ok' => true, 'historial' => $historial, 'botones' => $botones, 'avisos' => $this->servicio->contarAvisos()];
    }

    public function conversar(string $mensaje): array
    {
        $mensaje = trim($mensaje);
        if ($mensaje === '') {
            return ['ok' => false, 'error' => 'Escribe un mensaje.'];
        }
        $mensaje = mb_substr($mensaje, 0, self::MAX_MENSAJE, 'UTF-8');

        $idSesion = $this->obtenerSesion();
        $this->guardar($idSesion, 'Usuario', $mensaje);
        $resp = $this->servicio->responder($mensaje);
        $this->guardar($idSesion, 'Bot', $resp['texto']);

        return ['ok' => true, 'respuesta' => $resp, 'avisos' => $this->servicio->contarAvisos()];
    }

    /** Reutiliza la sesión activa reciente o crea otra (cierra las viejas). */
    private function obtenerSesion(): int
    {
        $stmt = $this->db->prepare(
            "SELECT id_sesion FROM sesiones_chat
             WHERE id_usuario = ? AND estado = 'Activa'
               AND fecha_inicio >= (NOW() - INTERVAL " . self::HORAS_SESION . " HOUR)
             ORDER BY id_sesion DESC LIMIT 1"
        );
        $stmt->execute([$this->idUsuario]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $this->db->prepare("UPDATE sesiones_chat SET estado = 'Finalizada', fecha_fin = NOW() WHERE id_usuario = ? AND estado = 'Activa'")
            ->execute([$this->idUsuario]);
        $this->db->prepare("INSERT INTO sesiones_chat (id_usuario) VALUES (?)")->execute([$this->idUsuario]);
        return (int) $this->db->lastInsertId();
    }

    private function guardar(int $idSesion, string $remitente, string $mensaje): void
    {
        $this->db->prepare("INSERT INTO mensajes_chat (id_sesion, remitente, mensaje) VALUES (?, ?, ?)")
            ->execute([$idSesion, $remitente, $mensaje]);
    }

    private function historial(int $idSesion): array
    {
        $stmt = $this->db->prepare(
            "SELECT remitente, mensaje FROM mensajes_chat
             WHERE id_sesion = ? ORDER BY id_mensaje DESC LIMIT " . self::MAX_HISTORIAL
        );
        $stmt->execute([$idSesion]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}