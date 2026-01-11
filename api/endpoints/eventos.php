<?php
// /tu_proyecto_api/api/endpoints/eventos.php

require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/request_handler.php';
require_once __DIR__ . '/../core/jwt_handler.php';

function handleEventosRequest($method, $data_input, $path_params, $query_params) {
    $conn = DBConnection::getConnection();
    $usuario_autenticado_data = JWTHandler::getAuthenticatedUserData();
    $usuario_id_autenticado = null;
    $is_authenticated_and_valid = false;

    if (is_object($usuario_autenticado_data) && isset($usuario_autenticado_data->userId)) {
        $usuario_id_autenticado = $usuario_autenticado_data->userId;
        $is_authenticated_and_valid = true;
    } elseif (is_array($usuario_autenticado_data) && isset($usuario_autenticado_data['error'])) {
        // GET /eventos y GET /eventos/{id} (sin /jugadores) pueden ser públicos
        $is_public_get = ($method === 'GET' && !isset($query_params['creados_por_mi']) && !(isset($path_params[0]) && isset($path_params[1]) && $path_params[1] === 'jugadores'));
        if (!$is_public_get) {
             RequestHandler::respondError("Acceso no autorizado: " . $usuario_autenticado_data['error'], 401);
             return;
        }
    }

    switch ($method) {
        case 'GET':
            // GET /eventos/{evento_id}/jugadores
            if (isset($path_params[0]) && is_numeric($path_params[0]) && isset($path_params[1]) && $path_params[1] === 'jugadores') {
                 // Este endpoint podría ser protegido o público. Por ahora, lo hacemos público.
                getJugadoresPorEvento($conn, (int)$path_params[0]);
            }
            // GET /eventos/{id}
            elseif (isset($path_params[0]) && is_numeric($path_params[0])) { 
                getEventoDetalle($conn, (int)$path_params[0]);
            } 
            // GET /eventos?filtro=creados
            elseif (isset($query_params['filtro']) && $query_params['filtro'] === 'creados') {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado. Token requerido para ver tus eventos creados.", 401);
                    return;
                }
                getEventosCreadosPorUsuario($conn, $usuario_id_autenticado);
            }
            // GET /eventos?filtro=participo
            elseif (isset($query_params['filtro']) && $query_params['filtro'] === 'participo') {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado. Token requerido para ver tus invitaciones a eventos.", 401);
                    return;
                }
                getEventosDondeFueInvitado($conn, $usuario_id_autenticado);
            }
            // GET /eventos?creados_por_mi=true (mantener compatibilidad)
            elseif (isset($query_params['creados_por_mi']) && $query_params['creados_por_mi'] === 'true') {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado. Token requerido para ver tus eventos creados.", 401);
                    return;
                }
                getEventosCreadosPorUsuario($conn, $usuario_id_autenticado);
            }
            // GET /eventos?invitado_a=true (mantener compatibilidad)
            elseif (isset($query_params['invitado_a']) && $query_params['invitado_a'] === 'true') {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado. Token requerido para ver tus invitaciones a eventos.", 401);
                    return;
                }
                getEventosDondeFueInvitado($conn, $usuario_id_autenticado);
            }
            // GET /eventos
            else { 
                getAllEventos($conn);
            }
            break;

        case 'POST': // POST /eventos (Crear evento)
            if (!$is_authenticated_and_valid) {
                RequestHandler::respondError("Acceso no autorizado para crear evento.", 401);
                return;
            }
            crearEvento($conn, $data_input, $usuario_id_autenticado);
            break;

        case 'PUT': // PUT /eventos/{id}
            if (isset($path_params[0]) && is_numeric($path_params[0])) {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado para actualizar evento.", 401);
                    return;
                }
                // PUT /eventos/{id}/dividir-gastos
                if (isset($path_params[1]) && $path_params[1] === 'dividir-gastos') {
                    actualizarDividirGastosAhora($conn, (int)$path_params[0], $data_input, $usuario_id_autenticado);
                } 
                // PUT /eventos/{id} con solo dividir_gastos_ahora (actualización parcial)
                elseif (isset($data_input['dividir_gastos_ahora']) && !isset($data_input['nombre'])) {
                    actualizarDividirGastosAhora($conn, (int)$path_params[0], $data_input, $usuario_id_autenticado);
                }
                else {
                    actualizarEvento($conn, (int)$path_params[0], $data_input, $usuario_id_autenticado);
                }
            } else {
                RequestHandler::respondError("ID de evento no especificado para actualizar.", 400);
            }
            break;

        case 'DELETE': // DELETE /eventos/{id}
            if (isset($path_params[0]) && is_numeric($path_params[0])) {
                if (!$is_authenticated_and_valid) {
                    RequestHandler::respondError("Acceso no autorizado para eliminar evento.", 401);
                    return;
                }
                eliminarEvento($conn, (int)$path_params[0], $usuario_id_autenticado);
            } else {
                RequestHandler::respondError("ID de evento no especificado para eliminar.", 400);
            }
            break;

        default:
            RequestHandler::respondError("Método no permitido para eventos.", 405);
            break;
    }
}

function getAllEventos($conn) {
    $stmt = $conn->prepare("CALL sp_GetAllEventos()");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare getAllEventos): " . $conn->error, 500); return; }
    if (!$stmt->execute()) { RequestHandler::respondError("SP Error (execute getAllEventos): " . $stmt->error, 500); $stmt->close(); return; }

    $result = $stmt->get_result();
    $eventos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $eventos[] = $row;
        }
        $result->free();
    }
    $stmt->close();
    RequestHandler::respondSuccess($eventos);
}

function getEventoDetalle($conn, $evento_id) {
    $stmt = $conn->prepare("CALL sp_GetEventoDetalle(?)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare getEventoDetalle): " . $conn->error, 500); return; }
    $stmt->bind_param("i", $evento_id);
    if (!$stmt->execute()) { RequestHandler::respondError("SP Error (execute getEventoDetalle): " . $stmt->error, 500); $stmt->close(); return; }

    $result = $stmt->get_result();
    $evento = $result->fetch_assoc();
    $result->free();
    $stmt->close();

    if ($evento) {
        // Convertir booleanos de la BD (0/1) a booleanos JSON (false/true)
        if (isset($evento['con_suplentes'])) $evento['con_suplentes'] = (bool)$evento['con_suplentes'];
        if (isset($evento['recurrente'])) $evento['recurrente'] = (bool)$evento['recurrente'];
        if (isset($evento['transmision_en_vivo'])) $evento['transmision_en_vivo'] = (bool)$evento['transmision_en_vivo'];
        if (isset($evento['dividir_gastos_ahora'])) $evento['dividir_gastos_ahora'] = (bool)$evento['dividir_gastos_ahora'];
        RequestHandler::respondSuccess($evento);
    } else {
        RequestHandler::respondError("Evento no encontrado.", 404);
    }
}

function getEventosCreadosPorUsuario($conn, $usuario_id) {
    $stmt = $conn->prepare("CALL sp_GetEventosPorUsuarioCreador(?)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare getEventosCreados): " . $conn->error, 500); return; }
    $stmt->bind_param("i", $usuario_id);
    if (!$stmt->execute()) { RequestHandler::respondError("SP Error (execute getEventosCreados): " . $stmt->error, 500); $stmt->close(); return; }

    $result = $stmt->get_result();
    $eventos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Convertir booleanos de la BD (0/1) a booleanos JSON (false/true)
            if (isset($row['con_suplentes'])) $row['con_suplentes'] = (bool)$row['con_suplentes'];
            if (isset($row['recurrente'])) $row['recurrente'] = (bool)$row['recurrente'];
            if (isset($row['transmision_en_vivo'])) $row['transmision_en_vivo'] = (bool)$row['transmision_en_vivo'];
            if (isset($row['dividir_gastos_ahora'])) $row['dividir_gastos_ahora'] = (bool)$row['dividir_gastos_ahora'];
            $eventos[] = $row;
        }
        $result->free();
    }
    $stmt->close();
    RequestHandler::respondSuccess($eventos);
}

function getJugadoresPorEvento($conn, $evento_id) {
    $stmt = $conn->prepare("CALL sp_GetJugadoresPorEvento(?)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare getJugadoresPorEvento): " . $conn->error, 500); return; }
    $stmt->bind_param("i", $evento_id);
    if (!$stmt->execute()) { RequestHandler::respondError("SP Error (execute getJugadoresPorEvento): " . $stmt->error, 500); $stmt->close(); return; }

    $result = $stmt->get_result();
    $jugadores = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $jugadores[] = $row;
        }
        $result->free();
    }
    $stmt->close();
    RequestHandler::respondSuccess($jugadores);
}

function crearEvento($conn, $data, $usuario_creador_id) {
    // Validar los datos mínimos requeridos
    if (empty($data['nombre']) || !isset($data['tamanio_cancha_id']) || !isset($data['opciones_equipo']) || !isset($data['con_suplentes'])) {
        RequestHandler::respondError("Datos incompletos para crear evento (nombre, cancha, opciones, suplentes).", 400);
        return;
    }

    // Recoger los datos del evento
    $nombre = $conn->real_escape_string($data['nombre']);
    $alias = isset($data['alias']) ? $conn->real_escape_string($data['alias']) : NULL;
    $tamanio_cancha_id = (int)$data['tamanio_cancha_id'];
    $opciones_equipo = (int)$data['opciones_equipo'];
    $con_suplentes = (int)$data['con_suplentes']; // Se enviará como 0 o 1
    $fecha = !empty($data['fecha']) ? $conn->real_escape_string($data['fecha']) : NULL;
    $hora = !empty($data['hora']) ? $conn->real_escape_string($data['hora']) : NULL;
    $recurrente = isset($data['recurrente']) ? (int)$data['recurrente'] : 0;
    $lista_dias = isset($data['lista_dias']) ? $conn->real_escape_string($data['lista_dias']) : NULL;
    $transmision_en_vivo = isset($data['transmision_en_vivo']) ? (int)$data['transmision_en_vivo'] : 0;

    // Lógica para el establecimiento
    $establecimiento_id = isset($data['establecimiento_id']) ? (int)$data['establecimiento_id'] : 0;
    $nombre_establecimiento_manual = isset($data['nombre_establecimiento_manual']) ? $conn->real_escape_string($data['nombre_establecimiento_manual']) : NULL;
    $direccion_manual = isset($data['direccion_manual']) ? $conn->real_escape_string($data['direccion_manual']) : NULL;
    $latitud_manual = isset($data['latitud_manual']) ? (float)$data['latitud_manual'] : NULL;
    $longitud_manual = isset($data['longitud_manual']) ? (float)$data['longitud_manual'] : NULL;
    $costo = isset($data['costo']) ? (float)$data['costo'] : 0;

    if ($establecimiento_id == 0 && empty($nombre_establecimiento_manual)) {
        RequestHandler::respondError("Se debe proveer un ID de establecimiento o un nombre para crear uno nuevo.", 400);
        return;
    }

    // El SP ahora tiene 17 parámetros de entrada + 1 de salida
    $stmt = $conn->prepare("CALL sp_CrearEvento(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @p_nuevo_evento_id)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare crearEvento): " . $conn->error, 500); return; }
    
    // String de tipos corregido: 17 caracteres, orden según SP actual
    $stmt->bind_param(
        "siiisssddssidsiii",
        $nombre,
        $tamanio_cancha_id, 
        $opciones_equipo, 
        $con_suplentes,
        $establecimiento_id, 
        $nombre_establecimiento_manual, 
        $direccion_manual,
        $latitud_manual, 
        $longitud_manual,
        $fecha, 
        $hora, 
        $recurrente, 
        $lista_dias,
        $costo,
        $alias,
        $transmision_en_vivo, 
        $usuario_creador_id
    );

    if ($stmt->execute()) {
        $select_out = $conn->query("SELECT @p_nuevo_evento_id AS nuevo_evento_id");
        if($select_out){
            $out_param = $select_out->fetch_assoc();
            $select_out->free();
            RequestHandler::respondSuccess(["mensaje" => "Evento creado exitosamente.", "nuevo_evento_id" => (int)$out_param['nuevo_evento_id']], 201);
        } else {
            RequestHandler::respondError("Error al obtener ID del nuevo evento: " . $conn->error, 500);
        }
    } else {
        RequestHandler::respondError("Error SP (execute crearEvento): " . $stmt->error, 500);
    }
    $stmt->close();
}

function actualizarEvento($conn, $evento_id, $data, $usuario_modificador_id) {
    $stmt_check = $conn->prepare("SELECT usuario_creador FROM eventos WHERE id = ?");
    if (!$stmt_check) { RequestHandler::respondError("SP Error (check prepare actualizarEvento): " . $conn->error, 500); return; }
    $stmt_check->bind_param("i", $evento_id);
    if (!$stmt_check->execute()) { RequestHandler::respondError("SP Error (check execute actualizarEvento): " . $stmt_check->error, 500); $stmt_check->close(); return; }

    $result_check = $stmt_check->get_result();
    $evento_db = $result_check->fetch_assoc();
    $result_check->free();
    $stmt_check->close();

    if (!$evento_db) {
        RequestHandler::respondError("Evento no encontrado.", 404);
        return;
    }
    if ($evento_db['usuario_creador'] != $usuario_modificador_id) {
        RequestHandler::respondError("No autorizado para modificar este evento.", 403);
        return;
    }

    if (empty($data['nombre']) || !isset($data['tamanio_cancha_id']) || !isset($data['opciones_equipo']) || !isset($data['con_suplentes']) || !isset($data['establecimiento_id']) || empty($data['fecha']) || empty($data['hora'])) {
        RequestHandler::respondError("Datos incompletos para actualizar evento.", 400);
        return;
    }
    $nombre = $conn->real_escape_string($data['nombre']);
    $alias = isset($data['alias']) ? $conn->real_escape_string($data['alias']) : NULL;
    $tamanio_cancha_id = (int)$data['tamanio_cancha_id'];
    $opciones_equipo = (int)$data['opciones_equipo'];
    $con_suplentes = (int)$data['con_suplentes'];
    $establecimiento_id = (int)$data['establecimiento_id'];
    $fecha = $conn->real_escape_string($data['fecha']);
    $hora = $conn->real_escape_string($data['hora']);
    $recurrente = isset($data['recurrente']) ? (int)$data['recurrente'] : 0;
    $lista_dias = isset($data['lista_dias']) ? $conn->real_escape_string($data['lista_dias']) : NULL;
    $transmision_en_vivo = isset($data['transmision_en_vivo']) ? (int)$data['transmision_en_vivo'] : 0;

    $stmt = $conn->prepare("CALL sp_ActualizarEvento(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @p_mensaje)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare sp_ActualizarEvento): " . $conn->error, 500); return; }
    $stmt->bind_param(
        "isiiisssisisi",
        $evento_id, $nombre, $alias, $tamanio_cancha_id, $opciones_equipo, $con_suplentes, $establecimiento_id,
        $fecha, $hora, $recurrente, $lista_dias, $transmision_en_vivo, $usuario_modificador_id
    );

    if ($stmt->execute()) {
        $select_out = $conn->query("SELECT @p_mensaje AS mensaje");
        if($select_out){
            $out_param = $select_out->fetch_assoc();
            $select_out->free();
            RequestHandler::respondSuccess(["mensaje" => $out_param['mensaje']]);
        } else {
            RequestHandler::respondError("Error al obtener mensaje de SP (actualizarEvento): " . $conn->error, 500);
        }
    } else {
        RequestHandler::respondError("Error SP (execute sp_ActualizarEvento): " . $stmt->error, 500);
    }
    $stmt->close();
}

function eliminarEvento($conn, $evento_id, $usuario_eliminador_id) {
    $stmt_check = $conn->prepare("SELECT usuario_creador FROM eventos WHERE id = ?");
    if (!$stmt_check) { RequestHandler::respondError("SP Error (check prepare eliminarEvento): " . $conn->error, 500); return; }
    $stmt_check->bind_param("i", $evento_id);
    if (!$stmt_check->execute()) { RequestHandler::respondError("SP Error (check execute eliminarEvento): " . $stmt_check->error, 500); $stmt_check->close(); return; }
    
    $result_check = $stmt_check->get_result();
    $evento_db = $result_check->fetch_assoc();
    $result_check->free();
    $stmt_check->close();

    if (!$evento_db) {
        RequestHandler::respondError("Evento no encontrado.", 404);
        return;
    }
    if ($evento_db['usuario_creador'] != $usuario_eliminador_id) {
        RequestHandler::respondError("No autorizado para eliminar este evento.", 403);
        return;
    }

    $stmt = $conn->prepare("CALL sp_EliminarEvento(?, @p_mensaje)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare sp_EliminarEvento): " . $conn->error, 500); return; }
    $stmt->bind_param("i", $evento_id);

    if ($stmt->execute()) {
        $select_out = $conn->query("SELECT @p_mensaje AS mensaje");
        if($select_out){
            $out_param = $select_out->fetch_assoc();
            $select_out->free();
            if (strpos(strtolower($out_param['mensaje']), 'error') === false) {
                 RequestHandler::respondSuccess(["mensaje" => $out_param['mensaje']]);
            } else {
                RequestHandler::respondError($out_param['mensaje'], 409); // 409 Conflict por FK
            }
        } else {
            RequestHandler::respondError("Error al obtener mensaje de SP (eliminarEvento): " . $conn->error, 500);
        }
    } else {
        RequestHandler::respondError("Error SP (execute sp_EliminarEvento): " . $stmt->error, 500);
    }
    $stmt->close();
}

function getEventosDondeFueInvitado($conn, $usuario_id) {
    $stmt = $conn->prepare("CALL sp_GetEventosPorInvitacionUsuario(?)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare getEventosDondeFueInvitado): " . $conn->error, 500); return; }
    
    $stmt->bind_param("i", $usuario_id);
    if (!$stmt->execute()) { RequestHandler::respondError("SP Error (execute getEventosDondeFueInvitado): " . $stmt->error, 500); $stmt->close(); return; }

    $result = $stmt->get_result();
    $eventos = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Convertir booleanos de la BD (0/1) a booleanos JSON (false/true)
            if (isset($row['con_suplentes'])) $row['con_suplentes'] = (bool)$row['con_suplentes'];
            if (isset($row['recurrente'])) $row['recurrente'] = (bool)$row['recurrente'];
            if (isset($row['transmision_en_vivo'])) $row['transmision_en_vivo'] = (bool)$row['transmision_en_vivo'];
            if (isset($row['dividir_gastos_ahora'])) $row['dividir_gastos_ahora'] = (bool)$row['dividir_gastos_ahora'];
            $eventos[] = $row;
        }
        $result->free();
    }
    $stmt->close();
    RequestHandler::respondSuccess($eventos);
}

function actualizarDividirGastosAhora($conn, $evento_id, $data, $usuario_id) {
    if (!isset($data['dividir_gastos_ahora'])) {
        RequestHandler::respondError("Falta el campo dividir_gastos_ahora.", 400);
        return;
    }

    $dividir_gastos_ahora = (int)$data['dividir_gastos_ahora'];

    $stmt = $conn->prepare("CALL sp_ActualizarDividirGastosAhora(?, ?, ?, @p_mensaje)");
    if (!$stmt) { RequestHandler::respondError("SP Error (prepare actualizarDividirGastosAhora): " . $conn->error, 500); return; }
    
    $stmt->bind_param("iii", $evento_id, $dividir_gastos_ahora, $usuario_id);

    if ($stmt->execute()) {
        $select_out = $conn->query("SELECT @p_mensaje AS mensaje");
        if ($select_out) {
            $out_param = $select_out->fetch_assoc();
            $select_out->free();
            if (strpos(strtolower($out_param['mensaje']), 'error') === false) {
                RequestHandler::respondSuccess(["mensaje" => $out_param['mensaje'], "dividir_gastos_ahora" => (bool)$dividir_gastos_ahora]);
            } else {
                $code = strpos($out_param['mensaje'], 'No autorizado') !== false ? 403 : 404;
                RequestHandler::respondError($out_param['mensaje'], $code);
            }
        } else {
            RequestHandler::respondError("Error al obtener mensaje de SP: " . $conn->error, 500);
        }
    } else {
        RequestHandler::respondError("Error SP (execute actualizarDividirGastosAhora): " . $stmt->error, 500);
    }
    $stmt->close();
}

?>
