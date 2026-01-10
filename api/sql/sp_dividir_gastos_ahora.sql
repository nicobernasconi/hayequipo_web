-- ============================================
-- Script para agregar campo dividir_gastos_ahora
-- Este campo se usa para eventos que completaron el mínimo pero tienen suplentes
-- COMPATIBLE CON VERSIONES ANTERIORES
-- ============================================

-- 1. Agregar el campo a la tabla eventos (solo si no existe)
SET @columnExists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'eventos' 
    AND COLUMN_NAME = 'dividir_gastos_ahora'
);

SET @sql = IF(@columnExists = 0, 
    'ALTER TABLE `eventos` ADD COLUMN `dividir_gastos_ahora` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Flag para dividir gastos cuando el evento completó el mínimo pero tiene suplentes''',
    'SELECT ''Columna dividir_gastos_ahora ya existe'' AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 2. Actualizar sp_GetEventoDetalle
-- Incluye dividir_gastos_ahora con IFNULL para compatibilidad
-- ============================================
DROP PROCEDURE IF EXISTS `sp_GetEventoDetalle`;
delimiter ;;
CREATE PROCEDURE `sp_GetEventoDetalle`(IN p_evento_id int)
BEGIN
    SELECT
        ev.id, ev.nombre, ev.tamanio_cancha_id, tc.cantidad_jugadores AS jugadores_cancha,
        ev.opciones_equipo, ev.con_suplentes, ev.alias,
        ev.establecimiento_id, ev.nombre_establecimiento, ev.direccion, ev.latitud, ev.longitud,
        ev.fecha, ev.hora, ev.recurrente, ev.lista_dias,
        ev.transmision_en_vivo,
        ev.usuario_creador, uc.nombre_completo AS nombre_creador, uc.email AS email_creador,
        ev.fecha_creacion, ev.fecha_modificacion,
        (SELECT COUNT(id) FROM invitaciones inv WHERE inv.evento_id=ev.id AND inv.estado_invitacion_id=1) AS invitaciones_aprobadas,
        ev.costo,
        (SELECT IFNULL(SUM(pu.monto), 0) FROM pagos_usuarios pu WHERE pu.evento_id = ev.id) AS total_pagado,
        IFNULL(ev.dividir_gastos_ahora, 0) AS dividir_gastos_ahora
    FROM
        eventos ev
    JOIN
        tamanio_cancha tc ON ev.tamanio_cancha_id = tc.id
    JOIN
        usuarios uc ON ev.usuario_creador = uc.id
    WHERE
        ev.id = p_evento_id;
END
;;
delimiter ;

-- ============================================
-- 3. Actualizar sp_GetEventosPorInvitacionUsuario
-- Incluye dividir_gastos_ahora con IFNULL para compatibilidad
-- ============================================
DROP PROCEDURE IF EXISTS `sp_GetEventosPorInvitacionUsuario`;
delimiter ;;
CREATE PROCEDURE `sp_GetEventosPorInvitacionUsuario`(IN `p_usuario_id` INT)
BEGIN
    SELECT
        ev.id,
        ev.nombre,
        ev.tamanio_cancha_id,
        tc.cantidad_jugadores AS cantidad_jugadores_cancha,
        ev.opciones_equipo,
        ev.con_suplentes,
        ev.establecimiento_id,
        ev.nombre_establecimiento,
        est.direccion AS direccion_establecimiento,
        ev.latitud,
        ev.longitud,
        ev.fecha,
        ev.hora,
        ev.recurrente,
        ev.lista_dias,
        ev.transmision_en_vivo,
        ev.usuario_creador,
        uc.nombre_completo AS nombre_creador,
        ev.fecha_creacion,
        ev.fecha_modificacion,
        (SELECT COUNT(id) FROM invitaciones inv WHERE inv.evento_id=ev.id AND inv.estado_invitacion_id=1) AS invitaciones_aprobadas,
        ei.nombre AS estado_invitacion_usuario,
        IFNULL(ev.dividir_gastos_ahora, 0) AS dividir_gastos_ahora
    FROM
        `eventos` ev
    JOIN `invitaciones` i ON ev.id = i.evento_id
    LEFT JOIN `tamanio_cancha` tc ON ev.tamanio_cancha_id = tc.id
    LEFT JOIN `usuarios` uc ON ev.usuario_creador = uc.id
    LEFT JOIN `establecimiento` est ON ev.establecimiento_id = est.id
    LEFT JOIN `estado_invitacion` ei ON i.estado_invitacion_id = ei.id
    WHERE
        i.usuario_invitado_id = p_usuario_id
        AND (i.estado_invitacion_id = 1 OR i.estado_invitacion_id = 2)
        AND ev.fecha >= NOW() - INTERVAL '1' DAY
    ORDER BY
        ev.fecha, ev.hora ASC;
END
;;
delimiter ;

-- ============================================
-- 4. Actualizar sp_GetEventosPorUsuarioCreador
-- Usa ev.* por lo que automáticamente incluirá el nuevo campo
-- ============================================
DROP PROCEDURE IF EXISTS `sp_GetEventosPorUsuarioCreador`;
delimiter ;;
CREATE PROCEDURE `sp_GetEventosPorUsuarioCreador`(IN p_usuario_id int)
BEGIN
    SELECT
        ev.*, 
        est.direccion AS direccion_establecimiento,
        tc.cantidad_jugadores AS jugadores_cancha,
        uc.nombre_completo AS creador_evento,
        (SELECT COUNT(id) FROM invitaciones inv WHERE inv.evento_id=ev.id AND inv.estado_invitacion_id=1) AS invitaciones_aprobadas
    FROM eventos ev
    JOIN establecimiento est ON ev.establecimiento_id = est.id
    JOIN tamanio_cancha tc ON ev.tamanio_cancha_id = tc.id
    JOIN usuarios uc ON ev.usuario_creador = uc.id
    WHERE ev.usuario_creador = p_usuario_id 
    AND ev.fecha >= NOW() - INTERVAL '1' DAY
    ORDER BY ev.fecha DESC, ev.hora ASC;
END
;;
delimiter ;

-- ============================================
-- 5. Nuevo SP para actualizar dividir_gastos_ahora
-- ============================================
DROP PROCEDURE IF EXISTS `sp_ActualizarDividirGastosAhora`;
delimiter ;;
CREATE PROCEDURE `sp_ActualizarDividirGastosAhora`(
    IN p_evento_id INT,
    IN p_dividir_gastos_ahora TINYINT,
    IN p_usuario_id INT,
    OUT p_mensaje VARCHAR(255)
)
BEGIN
    DECLARE v_usuario_creador INT;
    
    -- Verificar que el evento existe y obtener el creador
    SELECT usuario_creador INTO v_usuario_creador
    FROM eventos 
    WHERE id = p_evento_id;
    
    IF v_usuario_creador IS NULL THEN
        SET p_mensaje = 'Error: Evento no encontrado.';
    ELSEIF v_usuario_creador != p_usuario_id THEN
        SET p_mensaje = 'Error: No autorizado para modificar este evento.';
    ELSE
        UPDATE eventos 
        SET dividir_gastos_ahora = p_dividir_gastos_ahora,
            fecha_modificacion = NOW()
        WHERE id = p_evento_id;
        
        SET p_mensaje = 'Dividir gastos ahora actualizado correctamente.';
    END IF;
END
;;
delimiter ;
