-- 036_dashboard_bloqueios_admin.sql
-- Bloqueio administrativo de blocos do Dashboard por usuario. Separado de
-- dashboard_preferencias (self-service) porque savePrefs() faz DELETE+INSERT
-- completo das preferencias do proprio usuario a cada salvamento — se o
-- bloqueio do admin vivesse na mesma tabela, o usuario apagaria o bloqueio
-- sem querer na proxima vez que salvasse suas proprias preferencias.
CREATE TABLE IF NOT EXISTS dashboard_bloqueios_admin (
    usuario_id CHAR(36)    COLLATE utf8mb4_unicode_ci NOT NULL,
    widget     VARCHAR(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (usuario_id, widget),
    CONSTRAINT fk_dashbloq_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
