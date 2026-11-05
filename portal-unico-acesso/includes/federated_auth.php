<?php
/**
 * includes/federated_auth.php
 */
require_once __DIR__ . '/db_connect.php';

function verificar_senha(string $senhaDigitada, ?string $hashArmazenado, string $metodo): bool
{
    if ($hashArmazenado === null || $hashArmazenado === '') {
        return false;
    }

    return match ($metodo) {
        'bcrypt'    => password_verify($senhaDigitada, $hashArmazenado),
        'plaintext' => hash_equals($hashArmazenado, $senhaDigitada),
        default     => false,
    };
}

/**
 * Verifica e-mail+senha em cada sistema configurado. Sistemas com múltiplas
 * instâncias (mesmo cadastro, bancos separados) checam todas — basta bater
 * em uma pra considerar o sistema liberado.
 *
 * @param array $sistemas vindo de carregar_todos_sistemas()
 * @param array|null &$instanciasCorrespondentes opcional: preenchido com
 *        sistema => id da instância que bateu (só quando o status for true).
 *        Quem não precisa saber qual instância bateu (a maioria dos sistemas
 *        só tem uma) pode ignorar este parâmetro.
 * @return array<string, bool|null> true = bateu, false = e-mail existe mas senha não confere,
 *                                  null = e-mail não encontrado em nenhuma instância
 */
function autenticar_federado(string $email, string $senha, array $sistemas, ?array &$instanciasCorrespondentes = null): array
{
    $resultado = [];
    $instanciasCorrespondentes = [];

    foreach ($sistemas as $id => $cfg) {
        if (!empty($cfg['apenas_vitrine'])) {
            continue;
        }

        $statusSistema = null;

        foreach ($cfg['instancias'] as $instancia) {
            if (empty($instancia['db'])) {
                continue;
            }

            try {
                $pdo = conectar_sistema($instancia['db']);

                $sql = "SELECT {$cfg['coluna_senha']} AS senha_armazenada FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?";
                if (!empty($cfg['coluna_ativo'])) {
                    $sql .= " AND {$cfg['coluna_ativo']} = 1";
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email]);
                $row = $stmt->fetch();

                if (!$row) {
                    continue; // não encontrado nessa instância, tenta a próxima
                }

                if (verificar_senha($senha, $row['senha_armazenada'], $cfg['hash'])) {
                    $statusSistema = true;
                    $instanciasCorrespondentes[$id] = $instancia['id'];
                    break; // achou e bateu — não precisa checar as outras instâncias
                }

                $statusSistema = false; // achou o e-mail aqui, mas a senha não bate
            } catch (\PDOException $e) {
                error_log("Falha ao autenticar no sistema '$id' (instância '{$instancia['id']}'): " . $e->getMessage());
            }
        }

        $resultado[$id] = $statusSistema;
    }

    return $resultado;
}

/**
 * Sincroniza a senha digitada em toda instância, de todo sistema, onde o e-mail
 * existe mas a senha não bateu (status === false em $resultado, vindo de
 * autenticar_federado()). Assim, depois de um login bem-sucedido em pelo menos
 * uma instância, a mesma senha passa a valer nas outras também — sem precisar
 * que o usuário soubesse/digitasse a senha de cada uma.
 *
 * Não mexe em sistemas onde o e-mail não existe (status null) nem onde já bateu (true).
 *
 * @return array<string, bool|null> mesmo formato de autenticar_federado(), com os
 *                                  sistemas sincronizados agora marcados como true.
 */
function sincronizar_senha_federada(string $email, string $senha, array $sistemas, array $resultado): array
{
    foreach ($resultado as $id => $status) {
        if ($status !== false) {
            continue;
        }

        $cfg = $sistemas[$id];
        $sincronizouEmAlgumaInstancia = false;

        foreach ($cfg['instancias'] as $instancia) {
            if (empty($instancia['db'])) {
                continue;
            }

            try {
                $pdo = conectar_sistema($instancia['db']);
                $novoValor = $cfg['hash'] === 'plaintext' ? $senha : password_hash($senha, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("UPDATE {$cfg['tabela']} SET {$cfg['coluna_senha']} = ? WHERE {$cfg['coluna_email']} = ?");
                $stmt->execute([$novoValor, $email]);

                if ($stmt->rowCount() > 0) {
                    $sincronizouEmAlgumaInstancia = true;
                }
            } catch (\PDOException $e) {
                error_log("Falha ao sincronizar senha no sistema '$id' (instância '{$instancia['id']}'): " . $e->getMessage());
            }
        }

        if ($sincronizouEmAlgumaInstancia) {
            $resultado[$id] = true;
        }
    }

    return $resultado;
}

/**
 * Verifica se o e-mail+senha bate em algum sistema (em qualquer instância dele)
 * E a pessoa tem papel de admin lá. Usado só pra decidir se mostra o link de
 * administração do portal.
 */
function verificar_admin_federado(string $email, string $senha, array $sistemas): bool
{
    foreach ($sistemas as $id => $cfg) {
        if (empty($cfg['coluna_role']) || !isset($cfg['valor_admin'])) {
            continue;
        }

        foreach ($cfg['instancias'] as $instancia) {
            if (empty($instancia['db'])) {
                continue;
            }

            try {
                $pdo = conectar_sistema($instancia['db']);

                $sql = "SELECT {$cfg['coluna_senha']} AS senha_armazenada, {$cfg['coluna_role']} AS papel
                        FROM {$cfg['tabela']} WHERE {$cfg['coluna_email']} = ?";
                if (!empty($cfg['coluna_ativo'])) {
                    $sql .= " AND {$cfg['coluna_ativo']} = 1";
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email]);
                $row = $stmt->fetch();

                if (!$row) {
                    continue;
                }

                if (!verificar_senha($senha, $row['senha_armazenada'], $cfg['hash'])) {
                    continue;
                }

                if (strcasecmp((string)$row['papel'], (string)$cfg['valor_admin']) === 0) {
                    return true;
                }
            } catch (\PDOException $e) {
                error_log("Falha ao verificar admin no sistema '$id' (instância '{$instancia['id']}'): " . $e->getMessage());
            }
        }
    }

    return false;
}
