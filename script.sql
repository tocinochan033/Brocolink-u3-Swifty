-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 08-09-2026 a las 10:44:22
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `swift_invoice_db`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_buscar_facturas` (IN `tipoBusqueda` VARCHAR(20), IN `valorBusqueda` VARCHAR(100))   BEGIN

    SELECT 
        i.id, 
        i.invoice_number, 
        i.invoice_date, 
        i.created_at, 
        s.total, 
        s.client_type,

        CASE 
            WHEN s.client_type = 'person'
                THEN CONCAT(c.last_name, ' ', c.first_name)

            WHEN s.client_type = 'company'
                THEN co.business_name

            ELSE 'Desconocido'
        END AS client_name

    FROM invoices i

    JOIN sales s 
        ON i.sale_id = s.id

    LEFT JOIN clients c 
        ON s.client_type = 'person' 
        AND s.client_id = c.id

    LEFT JOIN companies co 
        ON s.client_type = 'company' 
        AND s.client_id = co.id

    WHERE
        (
            tipoBusqueda = 'folio'
            AND i.invoice_number LIKE CONCAT('%', valorBusqueda, '%')
        )

        OR

        (
            tipoBusqueda = 'fecha'
            AND DATE(i.invoice_date) = valorBusqueda
        )

    ORDER BY i.invoice_date DESC;

END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `audit_log`
--

CREATE TABLE `audit_log` (
  `id_audit` int(11) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `action_type` enum('INSERT','UPDATE','DELETE') DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `modified_by` varchar(100) DEFAULT NULL,
  `modification_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `audit_log`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `business_types`
--

CREATE TABLE `business_types` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `business_types`
--

INSERT INTO `business_types` (`id`, `name`) VALUES
(1, 'S.A. de C.V.'),
(2, 'S. de R.L.'),
(3, 'S. de R.L. de C.V.'),
(4, 'S.C.'),
(5, 'A.C.'),
(6, 'S.N.C.'),
(7, 'S.A.P.I. de C.V.'),
(8, 'S.A.'),
(9, 'S.R.L.'),
(10, 'Cooperativa');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `mother_last_name` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `rfc` varchar(13) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `tax_regime_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clients`
--


--
-- Disparadores `clients`
--
DELIMITER $$
CREATE TRIGGER `trg_clients_delete` AFTER DELETE ON `clients` FOR EACH ROW BEGIN

    DECLARE regimen_nombre VARCHAR(255);

    SELECT name
    INTO regimen_nombre
    FROM tax_regimes
    WHERE id = OLD.tax_regime_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        modified_by
    )
    VALUES (
        'clients',
        'DELETE',
        OLD.id,

        JSON_OBJECT(
            'first_name', OLD.first_name,
            'last_name', OLD.last_name,
            'mother_last_name', OLD.mother_last_name,
            'phone', OLD.phone,
            'email', OLD.email,
            'rfc', OLD.rfc,
            'address', OLD.address,
            'tax_regime_id', OLD.tax_regime_id,
            'tax_regime_name', regimen_nombre
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_clients_insert` AFTER INSERT ON `clients` FOR EACH ROW BEGIN

    DECLARE regimen_nombre VARCHAR(255);

    SELECT name
    INTO regimen_nombre
    FROM tax_regimes
    WHERE id = NEW.tax_regime_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        new_data,
        modified_by
    )
    VALUES (
        'clients',
        'INSERT',
        NEW.id,

        JSON_OBJECT(
            'first_name', NEW.first_name,
            'last_name', NEW.last_name,
            'mother_last_name', NEW.mother_last_name,
            'phone', NEW.phone,
            'email', NEW.email,
            'rfc', NEW.rfc,
            'address', NEW.address,
            'tax_regime_id', NEW.tax_regime_id,
            'tax_regime_name', regimen_nombre
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_clients_update` AFTER UPDATE ON `clients` FOR EACH ROW BEGIN

    DECLARE regimen_old VARCHAR(255);
    DECLARE regimen_new VARCHAR(255);

    SELECT name
    INTO regimen_old
    FROM tax_regimes
    WHERE id = OLD.tax_regime_id
    LIMIT 1;

    SELECT name
    INTO regimen_new
    FROM tax_regimes
    WHERE id = NEW.tax_regime_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        new_data,
        modified_by
    )
    VALUES (
        'clients',
        'UPDATE',
        NEW.id,

        JSON_OBJECT(
            'first_name', OLD.first_name,
            'last_name', OLD.last_name,
            'mother_last_name', OLD.mother_last_name,
            'phone', OLD.phone,
            'email', OLD.email,
            'rfc', OLD.rfc,
            'address', OLD.address,
            'tax_regime_id', OLD.tax_regime_id,
            'tax_regime_name', regimen_old
        ),

        JSON_OBJECT(
            'first_name', NEW.first_name,
            'last_name', NEW.last_name,
            'mother_last_name', NEW.mother_last_name,
            'phone', NEW.phone,
            'email', NEW.email,
            'rfc', NEW.rfc,
            'address', NEW.address,
            'tax_regime_id', NEW.tax_regime_id,
            'tax_regime_name', regimen_new
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `business_name` varchar(100) NOT NULL,
  `rfc` varchar(13) NOT NULL,
  `fiscal_address` text NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `legal_representative` varchar(100) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `business_type_id` int(11) DEFAULT NULL,
  `tax_regime_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `companies`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `current_audit_user`
--

CREATE TABLE `current_audit_user` (
  `id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `current_audit_user`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `invoice_number` varchar(20) NOT NULL,
  `invoice_date` date NOT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `invoices`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('Producto','Servicio') NOT NULL DEFAULT 'Producto',
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `products`
--


-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax_percentage` decimal(5,2) NOT NULL,
  `tax_amount` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `client_type` enum('person','company') NOT NULL DEFAULT 'person'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `sales`
--


--
-- Disparadores `sales`
--
DELIMITER $$
CREATE TRIGGER `trg_sales_delete` AFTER DELETE ON `sales` FOR EACH ROW BEGIN

    DECLARE cliente_nombre VARCHAR(255);

    SELECT CONCAT(first_name, ' ', last_name)
    INTO cliente_nombre
    FROM clients
    WHERE id = OLD.client_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        modified_by
    )
    VALUES (
        'sales',
        'DELETE',
        OLD.id,

        JSON_OBJECT(
            'client_id', OLD.client_id,
            'client_name', cliente_nombre,
            'sale_date', OLD.sale_date,
            'subtotal', OLD.subtotal,
            'tax_percentage', OLD.tax_percentage,
            'tax_amount', OLD.tax_amount,
            'total', OLD.total,
            'client_type', OLD.client_type
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sales_insert` AFTER INSERT ON `sales` FOR EACH ROW BEGIN

    DECLARE cliente_nombre VARCHAR(255);

    SELECT CONCAT(first_name, ' ', last_name)
    INTO cliente_nombre
    FROM clients
    WHERE id = NEW.client_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        new_data,
        modified_by
    )
    VALUES (
        'sales',
        'INSERT',
        NEW.id,

        JSON_OBJECT(
            'client_id', NEW.client_id,
            'client_name', cliente_nombre,
            'sale_date', NEW.sale_date,
            'subtotal', NEW.subtotal,
            'tax_percentage', NEW.tax_percentage,
            'tax_amount', NEW.tax_amount,
            'total', NEW.total,
            'client_type', NEW.client_type
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sales_update` AFTER UPDATE ON `sales` FOR EACH ROW BEGIN

    DECLARE cliente_nombre_old VARCHAR(255);
    DECLARE cliente_nombre_new VARCHAR(255);

    SELECT CONCAT(first_name, ' ', last_name)
    INTO cliente_nombre_old
    FROM clients
    WHERE id = OLD.client_id
    LIMIT 1;

    SELECT CONCAT(first_name, ' ', last_name)
    INTO cliente_nombre_new
    FROM clients
    WHERE id = NEW.client_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        new_data,
        modified_by
    )
    VALUES (
        'sales',
        'UPDATE',
        NEW.id,

        JSON_OBJECT(
            'client_id', OLD.client_id,
            'client_name', cliente_nombre_old,
            'sale_date', OLD.sale_date,
            'subtotal', OLD.subtotal,
            'tax_percentage', OLD.tax_percentage,
            'tax_amount', OLD.tax_amount,
            'total', OLD.total,
            'client_type', OLD.client_type
        ),

        JSON_OBJECT(
            'client_id', NEW.client_id,
            'client_name', cliente_nombre_new,
            'sale_date', NEW.sale_date,
            'subtotal', NEW.subtotal,
            'tax_percentage', NEW.tax_percentage,
            'tax_amount', NEW.tax_amount,
            'total', NEW.total,
            'client_type', NEW.client_type
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sale_details`
--

CREATE TABLE `sale_details` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `tax_rate` decimal(4,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `sale_details`
--


--
-- Disparadores `sale_details`
--
DELIMITER $$
CREATE TRIGGER `trg_sale_details_delete` AFTER DELETE ON `sale_details` FOR EACH ROW BEGIN

    DECLARE producto_nombre VARCHAR(255);

    SELECT name
    INTO producto_nombre
    FROM products
    WHERE id = OLD.product_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        modified_by
    )
    VALUES (
        'sale_details',
        'DELETE',
        OLD.id,

        JSON_OBJECT(
            'sale_id', OLD.sale_id,
            'product_id', OLD.product_id,
            'product_name', producto_nombre,
            'quantity', OLD.quantity,
            'unit_price', OLD.unit_price,
            'subtotal', OLD.subtotal,
            'tax_rate', OLD.tax_rate
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sale_details_insert` AFTER INSERT ON `sale_details` FOR EACH ROW BEGIN

    DECLARE producto_nombre VARCHAR(255);

    SELECT name
    INTO producto_nombre
    FROM products
    WHERE id = NEW.product_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        new_data,
        modified_by
    )
    VALUES (
        'sale_details',
        'INSERT',
        NEW.id,

        JSON_OBJECT(
            'sale_id', NEW.sale_id,
            'product_id', NEW.product_id,
            'product_name', producto_nombre,
            'quantity', NEW.quantity,
            'unit_price', NEW.unit_price,
            'subtotal', NEW.subtotal,
            'tax_rate', NEW.tax_rate
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sale_details_update` AFTER UPDATE ON `sale_details` FOR EACH ROW BEGIN

    DECLARE producto_old VARCHAR(255);
    DECLARE producto_new VARCHAR(255);

    SELECT name
    INTO producto_old
    FROM products
    WHERE id = OLD.product_id
    LIMIT 1;

    SELECT name
    INTO producto_new
    FROM products
    WHERE id = NEW.product_id
    LIMIT 1;

    INSERT INTO audit_log (
        table_name,
        action_type,
        record_id,
        old_data,
        new_data,
        modified_by
    )
    VALUES (
        'sale_details',
        'UPDATE',
        NEW.id,

        JSON_OBJECT(
            'sale_id', OLD.sale_id,
            'product_id', OLD.product_id,
            'product_name', producto_old,
            'quantity', OLD.quantity,
            'unit_price', OLD.unit_price,
            'subtotal', OLD.subtotal,
            'tax_rate', OLD.tax_rate
        ),

        JSON_OBJECT(
            'sale_id', NEW.sale_id,
            'product_id', NEW.product_id,
            'product_name', producto_new,
            'quantity', NEW.quantity,
            'unit_price', NEW.unit_price,
            'subtotal', NEW.subtotal,
            'tax_rate', NEW.tax_rate
        ),

        (
            SELECT username
            FROM current_audit_user
            ORDER BY id DESC
            LIMIT 1
        )
    );

END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `tax_regimes`
--

CREATE TABLE `tax_regimes` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `tax_regimes`
--

INSERT INTO `tax_regimes` (`id`, `name`) VALUES
(1, 'General de Ley Personas Morales'),
(2, 'Régimen Simplificado de Confianza'),
(3, 'Régimen de Incorporación Fiscal'),
(4, 'Régimen de Actividades Empresariales y Profesionales'),
(5, 'Régimen de Sueldos y Salarios'),
(6, 'Régimen de Arrendamiento'),
(7, 'Régimen de Personas Morales con Fines no Lucrativos'),
(8, 'Régimen de Enajenación de Bienes'),
(9, 'Régimen de Dividendos'),
(10, 'Régimen de Plataformas Digitales');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `users`
--


--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id_audit`);

--
-- Indices de la tabla `business_types`
--
ALTER TABLE `business_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD UNIQUE KEY `unique_rfc` (`rfc`);

--
-- Indices de la tabla `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_business_type` (`business_type_id`),
  ADD KEY `fk_tax_regime` (`tax_regime_id`);

--
-- Indices de la tabla `current_audit_user`
--
ALTER TABLE `current_audit_user`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indices de la tabla `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `sale_details`
--
ALTER TABLE `sale_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indices de la tabla `tax_regimes`
--
ALTER TABLE `tax_regimes`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id_audit` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT de la tabla `business_types`
--
ALTER TABLE `business_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `current_audit_user`
--
ALTER TABLE `current_audit_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT de la tabla `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT de la tabla `sale_details`
--
ALTER TABLE `sale_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT de la tabla `tax_regimes`
--
ALTER TABLE `tax_regimes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `companies`
--
ALTER TABLE `companies`
  ADD CONSTRAINT `fk_business_type` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`),
  ADD CONSTRAINT `fk_tax_regime` FOREIGN KEY (`tax_regime_id`) REFERENCES `tax_regimes` (`id`);

--
-- Filtros para la tabla `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);

--
-- Filtros para la tabla `sale_details`
--
ALTER TABLE `sale_details`
  ADD CONSTRAINT `sale_details_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `sale_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

CREATE TABLE IF NOT EXISTS `auth_login_attempts` (
  `ip_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ip_hash`),
  KEY `idx_auth_login_attempts_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
