<?php

$line_is_blank = true;
$logfile = __FILE__;
if (strtolower (substr ($logfile, -4)) == '.php') {
    $logfile = substr ($logfile, 0, strlen ($logfile) - 4) . '.log';
} else {
    $logfile .= '.log';
}

error_reporting (0);
set_error_handler ('php_error_handler', -1);

function exibe ($msg, $prefixo = "") {
    global $line_is_blank, $logfile;
    $next_line_is_blank = false;
    $prefixo = trim ('[' . date ('Y-m-d H:i:s') . '] ' . $prefixo) . ' ';
    if (substr ($msg, -1) == "\n") {
        $msg = substr ($msg, 0, strlen ($msg) - 1);
        $next_line_is_blank = true;
    }
    $msgtransformada = preg_replace ("/\n/s", "\n" . $prefixo, $msg);
    if ($line_is_blank) {
        $msgtransformada = $prefixo . $msgtransformada;
    }
    if ($next_line_is_blank) {
        $msgtransformada .= "\n";
    }
    $line_is_blank = $next_line_is_blank;
    echo ($msgtransformada);
    file_put_contents ($logfile, $msgtransformada, FILE_APPEND);
}

function prompt ($msg) {
    global $line_is_blank, $logfile;
    exibe ($msg);
    $resp = trim (fgets (STDIN));
    $line_is_blank = true;
    file_put_contents ($logfile, "\n", FILE_APPEND);
    return ($resp);
}

function sai ($codsaida) {
    if ($codsaida) {
        echo ("\n **** Pressione ENTER para continuar... ****\n");
        fgets (STDIN);
    }
    exibe ("\n[ .FIM. ]\n");
    exit ($codsaida);
}

function morre ($msg) {
    exibe ("\n" . $msg . "\n\n", '** ERRO **');
    exibe ("**** O programa nao foi executado com sucesso! ****\n\n");
    sai (1);
}

function aviso ($msg) {
    exibe ("\n" . $msg . "\n\n", '-- AVISO --');
}

function php_error_handler ($errno, $errstr, $errfile, $errline) {
    aviso ("Erro de PHP: '" . try_realpath ($errfile) . "': " . $errline . ": " . $errno . ": " . $errstr . " !");
    return (false);
}

//////////////////////////////////////////////////////////////////////

function main () {
    exibe (" ++++ Script do AMG1127 para importar e excluir mensagens antigas de e-mail. ++++\n\n");

    // Conectar-se ao servidor de IMAP
    $usuario = prompt ("Digite o nome de usuario para conexao: ");
    $senha = prompt ("Digite a senha de usuario para conexao (CUIDADO: ELA IRA APARECER NA TELA): ");
    $imapserver = "{imap.servidor.com.br/readonly}";
    $imapconn = imap_open ($imapserver . "INBOX", $usuario, $senha, OP_READONLY | OP_HALFOPEN);
    if ($imapconn === false) {
        morre ("#E001 - Impossivel abrir conexao IMAP para o servidor - " . imap_last_error ());
    }

    exibe ("\nIdentificando mensagens para importar na conexao IMAP...\n");

    // Enumerar as pastas de correio disponiveis
    $folderlist = imap_list ($imapconn, $imapserver, '*');
    if (! is_array ($folderlist)) {
        morre ("#E002 - Impossivel listar pastas existentes na caixa de correio IMAP - " . imap_last_error ());
    }

    // Varrer cada pasta no servidor IMAP e importar mensagens
    $lenimapserver = strlen ($imapserver);
    $folder_importar = array ();
    $outrousuario = 'user.outro-usuario.';
    $len_outrousuario = strlen ($outrousuario);
    foreach ($folderlist as $folder) {
        if (substr ($folder, 0, $lenimapserver) != $imapserver) {
            morre ("#E003 - Sintaxe de pasta invalida: '" . $folder . "'");
        } else {
            $folder = substr ($folder, $lenimapserver);
            if (substr ($folder . '.', 0, $len_outrousuario) == $outrousuario) {
                $folder_importar[] = $folder;
            }
        }
    }
    
    foreach ($folder_importar as $folder) {
        importa_pasta ($imapconn, $imapserver, $folder);
    }

    if (! imap_close ($imapconn, CL_EXPUNGE)) {
        morre ("#E010 - Impossivel fechar conexao IMAP com o servidor - " . $imap_last_error ());
    }
    
    exibe ("\nExtraindo mensagens ja existentes no Thunderbird...\n");

    // Obter as mensagens guardadas na estrutura de pastas do Thunderbird (arquivos no formato MailBox)
    $thunderbird_dir = dirname (__FILE__) . '/ThunderbirdPortable/Data/profile/Mail/Local Folders';
    if (file_exists ($thunderbird_dir . '_old')) {
        morre ("#E047 - O arquivo ou a pasta '" . try_realpath ($thunderbird_dir . '_old') . "' existe. Por medidas de precaucao, o script sera abortado. Talvez este script nao funcionou na execucao anterior e, por isso, o backup antigo do Thunderbird foi deixado vivo no sistema de arquivos...");
    }
    $local_rootdir = dirname (__FILE__) . '/local-storage';
    $fila = array ();
    $fila[] = array ($thunderbird_dir, $local_rootdir);
    while (($pasta = array_shift ($fila)) !== null) {
        $thund = $pasta[0];
        $locald = $pasta[1];
        $dd = opendir ($thund);
        if (! $dd) {
            morre ("#E011 - Impossivel abrir pasta '" . try_realpath ($pasta) . "'!");
        }
        while (($d_entry = readdir ($dd)) !== false) {
            if ($d_entry != '.' && $d_entry != '..') {
                $fpath = $thund . '/' . $d_entry;
                if (is_dir ($fpath)) {
                    if (strtolower (substr ($d_entry, -4)) != '.sbd') {
                        morre ("#E012 - Sintaxe de subpasta invalida: '" . try_realpath ($fpath) . "'");
                    }
                    $fila[] = array ($fpath, $locald . '/' . substr ($d_entry, 0, strlen ($d_entry) - 4));
                } else if (is_file ($fpath)) {
                    $pos = strrpos ($d_entry, '.');
                    if ($pos === false || (strlen ($d_entry) - $pos) >= 10) {
                        exibe ("Abrindo '" . try_realpath ($fpath) . "'...");
                        $conta_importados = 0;
                        $conta_repetidos = 0;
                        $filedir = $locald . '/' . $d_entry;
                        if (! is_dir ($filedir)) {
                            if (! mkdir ($filedir, 0755, true)) {
                                morre ("#E013 - Impossivel criar pasta local '" . try_realpath ($filedir) . "'!");
                            }
                        }
                        // Estou com problema para tratar casos onde o sistema de arquivos eh sensivel a maiusculas e minusculas e
                        // casos onde o sistema de arquivos eh insensivel. Por isso, vou reler a pasta e os arquivos...
                        // Menos desempenho, mais confiabilidade...
                        $jabaixou = array ();
                        $dd2 = opendir ($filedir);
                        if (! $dd2) {
                            morre ("#E015 - Impossivel abrir pasta '" . try_realpath ($filedir) . "'!");
                        }
                        while (($d2_entry = readdir ($dd2)) !== false) {
                            if ($d2_entry != '.' && $d2_entry != '..' && strtolower (substr ($d2_entry, -4)) == '.eml') {
                                $f2path = $filedir . '/' . $d2_entry;
                                if (is_file ($f2path)) {
                                    $msgid = obtem_message_id ($f2path);
                                    if ($msgid !== false) {
                                        if (array_key_exists ($msgid, $jabaixou)) {
                                            morre ("#E016 - Neste bloco de codigo, nao deveriam ser encontradas mensagens duplicadas!");
                                        }
                                        $jabaixou[$msgid] = $f2path;
                                        exibe (',');
                                    }
                                }
                            }
                        }
                        closedir ($dd2);
                        clearstatcache (true);
                        // Agora, ler o arquivo 'MailBox'...
                        $fd = fopen ($fpath, "rb");
                        if (! $fd) {
                            morre ("#E017 - Impossivel abrir arquivo '" . try_realpath ($fpath) . "' para leitura!");
                        }
                        $fila_leit = array ();
                        for ($i = 0; $i < 3; $i++) {
                            $linha = fgets ($fd);
                            if ($linha !== false) {
                                $fila_leit[] = $linha;
                            } else if (! empty ($fila_leit)) {
                                morre ("#E032 - EOF prematuro durante leitura do arquivo '" . try_realpath ($fpath) . "'!");
                            }
                        }
                        $cntlinha = 0;
                        while (($linha = array_shift ($fila_leit)) !== null) {
                            $cntlinha++;
                            if (preg_match ("/^From - (Sun|Mon|Tue|Wed|Thu|Fri|Sat) (Jan|Feb|Mar|Apr|Mai|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-3]\\d [012]\\d:[0-5]\\d:[0-5]\\d \\d\\d\\d\\d\\s*\$/", $linha)) {
                                $linha = bota_e_tira ($fila_leit, $cntlinha, $fd);
                                if (preg_match ("/^X-Mozilla-Status: [a-fA-F0-9]+\\s*\$/", $linha)) {
                                    $linha = bota_e_tira ($fila_leit, $cntlinha, $fd);
                                    if (preg_match ("/^X-Mozilla-Status2: [a-fA-F0-9]+\\s*\$/", $linha)) {
                                        $msg_inicio = $cntlinha - 2;
                                        $msg_email = "";
                                        while (1) {
                                            $linha = bota_e_tira ($fila_leit, $cntlinha, $fd);
                                            if ($linha === null) {
                                                if (feof ($fd)) {
                                                    break;
                                                } else {
                                                    morre ("#E021 - Erro de leitura na linha #" . $cntlinha . " do arquivo '" . try_realpath ($fpath) . "'!");
                                                }
                                            } else if ($linha == "\n" || $linha == "\r" || $linha == "\r\n") {
                                                $linha_post = bota_e_tira ($fila_leit, $cntlinha, $fd);
                                                $cntlinha--;
                                                if ($linha_post === null) {
                                                    // Alcance do final do arquivo...
                                                    // $msg_email .= $linha;
                                                } else if (substr ($linha_post, 0, 7) == 'From - ') {
                                                    // Delimitador da mensagem seguinte. Final desta mensagem...
                                                    array_unshift ($fila_leit, $linha_post);
                                                    break;
                                                } else {
                                                    // Linha em branco faz parte da mensagem atual...
                                                    $msg_email .= $linha;
                                                    array_unshift ($fila_leit, $linha_post);
                                                }
                                            } else if (substr ($linha, 0, 7) == 'From - ') {
                                                morre ("#E022 - Erro detectando limite de mensagem na linha #" . $cntlinha . " do arquivo '" . try_realpath ($fpath) . "'!");
                                            } else {
                                                $msg_email .= $linha;
                                            }
                                        }
                                        $objcabec = imap_rfc822_parse_headers ($msg_email);
                                        if (! is_object ($objcabec)) {
                                            morre ("#E023 - Falha ao analisar cabecalhos da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . try_realpath ($fpath) . "'!");
                                        }
                                        if (empty ($objcabec->message_id)) {
                                            morre ("#E024 - Falha ao identificar ID da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . try_realpath ($fpath) . "'!");
                                        }
                                        if (array_key_exists ($objcabec->message_id, $jabaixou)) {
                                            $conta_repetidos++;
                                            exibe (':');
                                            $f2path = $jabaixou[$objcabec->message_id];
                                            if (! compara_mensagens ($msg_email, $f2path, true)) {
                                                morre ("#E025 - Verificacao de integridade falhou entre o arquivo '" . try_realpath ($f2path) . "' e a mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . try_realpath ($fpath) . "'!");
                                            }
                                        } else {
                                            $nomenovo = escolhe_nome_arquivo ($msg_email, $filedir);
                                            $caminhonovo = $filedir . '/' . $nomenovo;
                                            $bytes = file_put_contents ($caminhonovo, $msg_email);
                                            if ($bytes === false) {
                                                morre ("#E026 - Impossivel gravar arquivo '" . try_realpath ($caminhonovo) . "' com o conteudo da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . try_realpath ($fpath) . "'!");
                                            } else if (! compara_mensagens ($msg_email, $caminhonovo, false)) {
                                                morre ("#E027 - Falha na comparacao do conteudo do arquivo '" . try_realpath ($caminhonovo) . "' com o conteudo da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . try_realpath ($fpath) . "'!\n\nSera disco cheio ou danificado?");
                                            } else {
                                                exibe ('*');
                                                $conta_importados++;
                                                $jabaixou[$objcabec->message_id] = $caminhonovo;
                                            }
                                        }
                                    } else {
                                        morre ("#E020 - Erro detectando cabecalho 'X-Mozilla-Status2' na linha #" . $cntlinha . " do arquivo '" . try_realpath ($fpath) . "'!");
                                    }
                                } else {
                                    morre ("#E019 - Erro detectando cabecalho 'X-Mozilla-Status' na linha #" . $cntlinha . " do arquivo '" . try_realpath ($fpath) . "'!");
                                }
                            } else {
                                morre ("#E018 - Erro detectando cabecalho 'From - ' na linha #" . $cntlinha . " do arquivo '" . try_realpath ($fpath) . "'!");
                            }
                        }
                        fclose ($fd);
                        exibe ("\n +++ " . $conta_importados . " extraido + " . $conta_repetidos . " duplicado!\n");
                    } else if ($d_entry != 'msgFilterRules.dat') { // Arquivo que deve ser ignorado...
                        $ext = strtolower (substr ($d_entry, $pos));
                        if ($ext != '.msf') {
                            morre ("#E014 - Encontrado arquivo estranho na pasta de mensagens do Thunderbird: '" . try_realpath ($fpath) . "'!");
                        }
                    }
                }
            }
        }
        closedir ($dd);
        clearstatcache (true);
    }
    
    // Remontar os arquivos MailBox. Destruir os arquivos '*.msf', pois o Thunderbird os recria e nao ha nada importante la...
    exibe ("\nReconstruindo estrutura de pastas do Thunderbird em um espaco temporario...\n");
    $new_thunderbird_dir = dirname (__FILE__) . '/thunderbird-temp';
    recursive_remove ($new_thunderbird_dir);
    if (file_exists ($new_thunderbird_dir)) {
        morre ("#E031 - Impossivel apagar pasta de arquivos temporarios!");
    }
    if (! mkdir ($new_thunderbird_dir, 0755)) {
        morre ("#E033 - Impossivel criar nova pasta para arquivos temporarios!");
    }
    $fila = array ();
    $dd = opendir ($local_rootdir);
    if (! $dd) {
        morre ("#E034 - Impossivel abrir pasta '" . try_realpath ($local_rootdir) . "' para leitura!");
    }
    while (($d_entry = readdir ($dd)) !== false) {
        if ($d_entry != '.' && $d_entry != '..') {
            $fpath = $local_rootdir . '/' . $d_entry;
            if (is_dir ($fpath)) {
                $fila[] = array ($fpath, $new_thunderbird_dir . '/' . $d_entry);
            } else {
                morre ("#E035 - Encontrado arquivo estranho na pasta de armazenamento principal: '" . try_realpath ($fpath) . "'!");
            }
        }
    }
    closedir ($dd);
    while (($elems = array_shift ($fila)) !== null) {
        $pasta = $elems[0];
        $thunddir = $elems[1];
        exibe ("Construindo '" . try_realpath ($pasta) . "'...");
        $dd = opendir ($pasta);
        if (! $dd) {
            morre ("#E036 - Impossivel abrir pasta '" . try_realpath ($pasta) . "' para leitura!");
        }
        $fd = fopen ($thunddir, "wb");
        if (! $fd) {
            morre ("#E037 - Impossivel criar arquivo '" . try_realpath ($thunddir) . "'!");
        }
        $virgem = true;
        while (($d_entry = readdir ($dd)) !== false) {
            if ($d_entry != '.' && $d_entry != '..') {
                $fpath = $pasta . '/' . $d_entry;
                if (is_dir ($fpath)) {
                    $thundsubdir = $thunddir . '.sbd';
                    if (! is_dir ($thundsubdir)) {
                        if (! mkdir ($thundsubdir, 0755)) {
                            morre ("#E038 - Impossivel criar pasta '" . try_realpath ($thundsubdir) . "'!");
                        }
                    }
                    $fila[] = array ($fpath, $thundsubdir . '/' . $d_entry);
                } else if (is_file ($fpath)) {
                    if (strtolower (substr ($d_entry, -4)) == '.eml') {
                        $prefixo = 'From - ' . date ('D M d H:i:s Y') . "\r\n";
                        if ($virgem) {
                            $virgem = false;
                        } else {
                            $prefixo = "\r\n" . $prefixo;
                        }
                        $msg_email = file_get_contents ($fpath);
                        if ($msg_email === false) {
                            morre ("#E040 - Impossivel ler arquivo '" . try_realpath ($fpath) . "'!");
                        }
                        if (substr ($msg_email, -1) != "\n" && substr ($msg_email, -1) != "\r") {
                            $msg_email .= "\r\n";
                        }
                        if (strpos ($msg_email, "\nFrom - ") !== false || strpos ($msg_email, "\rFrom - ") !== false || substr ($msg_email, 0, 7) == 'From - ') {
                            // Se eu deixar mensagens com essa string passar, terei problemas no futuro, quando precisar analisar os arquivos 'MailBox' do Thunderbird...
                            morre ("#E044 - Encontrada string problematica no arquivo '" . try_realpath ($fpath) . "'!");
                        }
                        $prefixo .= "X-Mozilla-Status: 0001\r\n";
                        // Jeito bem "gambiarrento" de detectar anexos em uma mensagem... Que nojo!
                        if (strpos ($msg_email, "Content-Disposition: attachment;") !== false) {
                            $prefixo .= "X-Mozilla-Status2: 10000000\r\n";
                        } else {
                            $prefixo .= "X-Mozilla-Status2: 00000000\r\n";
                        }
                        $len_msg_email = strlen ($prefixo) + strlen ($msg_email);
                        if (fwrite ($fd, $prefixo . $msg_email) !== $len_msg_email) {
                            morre ("#E041 - Impossivel gravar dados no arquivo '" . try_realpath ($thunddir) . "'!");
                        }
                        exibe ('"');
                    } else {
                        morre ("#E039 - Encontrado arquivo estranho na pasta de armazenamento principal: '" . try_realpath ($fpath) . "'!");
                    }
                }
            }
        }
        closedir ($dd);
        if (! fflush ($fd)) {
            morre ("#E042 - Falha ao descarregar buffer de gravacao do arquivo '" . try_realpath ($thunddir) . "' que estava sendo gravado! Pode ter ocorrido um erro de gravacao...");
        }
        if (! fclose ($fd)) {
            morre ("#E043 - Falha ao fechar o arquivo '" . try_realpath ($thunddir) . "' que estava sendo gravado! Pode ter ocorrido um erro de gravacao...");
        }
        exibe (" OK!\n");
    }
    
    // Tudo foi feito em um espaco de armazenamento temporario. Se tudo deu certo ate aqui, a pasta podera "ir para o ambiente de producao"...
    if (! rename ($thunderbird_dir, $thunderbird_dir . '_old')) {
        morre ("#E045 - Impossivel renomear a pasta com as mensagens arquivadas do Thunderbird!");
    }
    if (! rename ($new_thunderbird_dir, $thunderbird_dir)) {
        morre ("#E046 - Impossivel colocar em producao a nova estrutura de pasta de mensagens arquivadas do Thunderbird!");
    }
    
    exibe ("\nA operacao de importacao foi realizada com sucesso!\nVou limpar a minha sujeira; se preferir, voce pode acessar os arquivos do Thunderbird agora...\n");
    recursive_remove ($thunderbird_dir . '_old');
}

function recursive_remove ($path) {
    if (is_dir ($path)) {
        $dd = opendir ($path);
        if ($dd) {
            while (($d_entry = readdir ($dd)) !== false) {
                if ($d_entry != '.' && $d_entry != '..') {
                    recursive_remove ($path . '/' . $d_entry);
                }
            }
            closedir ($dd);
        }
        rmdir ($path);
    } else if (file_exists ($path)) {
        unlink ($path);
    }
}

function try_realpath ($f) {
    @ $resp = realpath ($f);
    if ($resp !== false) {
        return ($resp);
    } else {
        return ($f);
    }
}

function bota_e_tira (&$matriz, &$pos, $fd) {
    if (! feof ($fd)) {
        $linha = fgets ($fd);
        if ($linha !== false) {
            $matriz[] = $linha;
        }
    }
    $pos++;
    return (array_shift ($matriz));
}

function importa_pasta ($imapconn, $imapserver, $folder) {
    /* Apos os testes de "parse" nos arquivos do Thunderbird, remover ou comentar esta linha */ aviso ("#A008 - testando..."); return;
    exibe ("Explorando pasta '" . $folder . "'...");

    $rootdir = dirname (__FILE__) . '/local-storage/' . str_replace ('.', '/', $folder);
    if (! is_dir ($rootdir)) {
        if (! mkdir ($rootdir, 0755, true)) {
            morre ("#E028 - Impossivel criar pasta local '" . try_realpath ($rootdir) . "'!");
        }
    }
    // Obter mensagens EML da pasta 
    $jabaixou = array ();
    $dd = opendir ($rootdir);
    if (! $dd) {
        morre ("#E009 - Impossivel abrir pasta '" . try_realpath ($rootdir) . "'!");
    }
    while (($d_entry = readdir ($dd)) !== false) {
        if ($d_entry != '.' && $d_entry != '..' && strtolower (substr ($d_entry, -4)) == '.eml') {
            $fpath = $rootdir . '/' . $d_entry;
            if (is_file ($fpath)) {
                $msgid = obtem_message_id ($fpath);
                if ($msgid !== false) {
                    if (array_key_exists ($msgid, $jabaixou)) {
                        // Mensagem duplicada na pasta ???
                        verifica_e_apaga ($jabaixou[$msgid], $fpath);
                        exibe ("'");
                    } else {
                        $jabaixou[$msgid] = $fpath;
                        exibe ('|');
                    }
                }
            }
        }
    }
    closedir ($dd);
    clearstatcache (true);
    
    if (! imap_reopen ($imapconn, $imapserver . $folder, OP_READONLY)) {
        morre ("#E004 - Impossivel abrir pasta '" . $folder . "': " . imap_last_error ());
    }
    
    $maillist = imap_search ($imapconn, "UNDELETED BEFORE \"" . date ('r', mktime (0, 0, 0, 1, 1, 2011)) . "\"", SE_UID);
    if (is_array ($maillist)) {
        $conta_importados = 0;
        $conta_repetidos = 0;
        foreach ($maillist as $mailitem) {
            // Observar cabecalhos da mensagem...
            $cabec = imap_fetchheader ($imapconn, $mailitem, FT_UID | FT_PREFETCHTEXT);
            if (empty ($cabec) && $cabec !== '') {
                morre ("#E005 - Impossivel ler cabecalhos da mensagem #" . $mailitem . " da pasta '" . $folder . "': " . imap_last_error ());
            }
            $objcabec = imap_rfc822_parse_headers ($cabec);
            if (! is_object ($objcabec)) {
                morre ("#E007 - Impossivel analisar cabecalhos da mensagem #" . $mailitem . " da pasta '" . $folder . "'!");
            }
            if (empty ($objcabec->message_id)) {
                morre ("#E008 - Impossivel determinar 'Message-ID' da mensagem #" . $mailitem . " da pasta '" . $folder . "'!");
            }
            $corpo = imap_body ($imapconn, $mailitem, FT_UID | FT_PEEK);
            if (empty ($corpo) && $corpo !== '') {
                morre ("#E006 - Impossivel ler o corpo da mensagem #" . $mailitem . " da pasta '" . $folder . "': " . imap_last_error ());
            }
            $todamensagem = $cabec . $corpo;
            if (strpos ($todamensagem, "\nFrom - ") !== false || strpos ($todamensagem, "\rFrom - ") !== false || substr ($todamensagem, 0, 7) == 'From - ') {
                // Se eu deixar mensagens com essa string passar, terei problemas no futuro, quando precisar analisar os arquivos 'MailBox' do Thunderbird...
                aviso ("#A007 - Encontrada string problematica na mensagem #" . $mailitem . " da pasta '" . $folder . "'. Por isso, essa mensagem nao sera importada!");
            } else {
                $faz_remocao = false;
                // A mensagem eh repetida?
                if (array_key_exists ($objcabec->message_id, $jabaixou)) {
                    if (compara_mensagens ($todamensagem, $jabaixou[$objcabec->message_id], true)) {
                        exibe ('-');
                        $conta_repetidos++;
                        $faz_remocao = true;
                    } else {
                        aviso ("#A004 - Falha na verificacao de integridade entre o conteudo do arquivo '" . try_realpath ($jabaixou[$objcabec->message_id]) . "' e o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'.");
                    }
                } else {
                    $fname = escolhe_nome_arquivo ($cabec, $rootdir);
                    $fpath = $rootdir . '/' . $fname;
                    $bytes = file_put_contents ($fpath, $todamensagem);
                    if ($bytes === false) {
                        aviso ("#A005 - Impossivel gravar arquivo '" . try_realpath ($fpath) . "' com o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'!");
                        must_unlink ($fpath);
                    } else if (! compara_mensagens ($todamensagem, $fpath, false)) {
                        aviso ("#A006 - Falha na comparacao do conteudo do arquivo '" . try_realpath ($fpath) . "' com o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'!\n\nSera disco cheio ou danificado?");
                        must_unlink ($fpath);
                    } else {
                        exibe ('.');
                        $jabaixou[$objcabec->message_id] = $fpath;
                        $conta_importados++;
                        $faz_remocao = true;
                    }
                }
                if ($faz_remocao) {
                    // Apagar a mensagem do servidor, desde que nao se esteja explorando a pasta 'Acesso' ou alguma das pastas 'atd - *'...
                    // CUIDADO: "Atd" tambem nao pode ser apagado!
                    if (strpos ($folder, '.Acesso.') === false && substr ($folder, -7) != '.Acesso' && strpos ($folder, '.atd - ') === false) {
                        imap_delete ($imapconn, $mailitem, FT_UID);
                    }
                }
            }
        }
        
        exibe ("\n +++ " . $conta_importados . " importado + " . $conta_repetidos . " repetido!\n");
    } else {
        exibe (" Nada para importar.\n");
    }
}

function verifica_e_apaga ($f_deixa, $f_apaga) {
    $arqc = file_get_contents ($f_deixa);
    if ($arqc === false) {
        morre ("#E030 - Impossivel abrir arquivo '" . try_realpath ($f_deixa) . "' para leitura!");
    }
    if (compara_mensagens ($arqc, $f_apaga, true)) {
        must_unlink ($f_apaga);
    }
}

function must_unlink ($f) {
    clearstatcache (true);
    if (file_exists ($f)) {
        if (! unlink ($f)) {
            morre ("#E029 - Impossivel excluir arquivo '" . try_realpath ($f) . "'!\nALERTA: remova manualmente o arquivo antes de voltar a executar este script; caso contrario, a integridade das mensagens armazenadas nao sera garantida.");
        }
    }
}

function compara_mensagens ($mensagem, $arquivo, $relax) {
    // Funcao que compara o conteudo de uma mensagem com o conteudo de um arquivo
    // Se $relax = true, a verificacao sera mais relaxada (comparando somente o corpo e algumas informacoes de cabecalho)...
    $sha1msg = sha1 ($mensagem);
    $arqc = file_get_contents ($arquivo);
    if ($arqc !== false) {
        if ($relax) {
            // Se ambos forem mensagens de e-mail, suprimir os cabecalhos e comparar somente o corpo
            $pos = strpos ($mensagem, "\r\n\r\n");
            if ($pos !== false) {
                $objcabec = imap_rfc822_parse_headers ($mensagem);
                if (is_object ($objcabec)) {
                    $mensagem = trim (substr ($mensagem, $pos));
                    $sha1msg = sha1 ($mensagem);
                    $pos = strpos ($arqc, "\r\n\r\n");
                    if ($pos !== false) {
                        $objcabec2 = imap_rfc822_parse_headers ($arqc);
                        if (is_object ($objcabec2)) {
                            $arqc = trim (substr ($arqc, $pos));
                            $prop_comps_str_obrig = array ('message_id', 'date');
                            $prop_comps_str_opcion = array ('subject', 'references');
                            $prop_comps_obj_opcion = array ('from', 'to', 'cc', 'reply_to');
                            $prop_comps_obj_obj = array ('personal', 'adl', 'mailbox', 'host');
                            foreach ($prop_comps_str_obrig as $p) {
                                if (empty ($objcabec->$p) || empty ($objcabec2->$p)) {
                                    return (false);
                                }
                                if ($objcabec->$p !== $objcabec2->$p) {
                                    return (false);
                                }
                            }
                            foreach ($prop_comps_str_opcion as $p) {
                                if (! (empty ($objcabec->$p) && empty ($objcabec2->$p))) {
                                    if (empty ($objcabec->$p) || empty ($objcabec2->$p)) {
                                        return (false);
                                    } else if ($objcabec->$p !== $objcabec2->$p) {
                                        return (false);
                                    }
                                }
                            }
                            foreach ($prop_comps_obj_opcion as $p) {
                                if (! (empty ($objcabec->$p) && empty ($objcabec2->$p))) {
                                    foreach ($objcabec->$p as $elem) {
                                        $perdido = true;
                                        foreach ($objcabec2->$p as $outroelem) {
                                            $igual = true;
                                            foreach ($prop_comps_obj_obj as $t) {
                                                if (! (empty ($elem->$t) && empty ($outroelem->$t))) {
                                                    if (empty ($elem->$t) || empty ($outroelem->$t)) {
                                                        $igual = false;
                                                        break;
                                                    } else if ($elem->$t !== $outroelem->$t) {
                                                        $igual = false;
                                                        break;
                                                    }
                                                }
                                            }
                                            if ($igual) {
                                                $perdido = false;
                                                break;
                                            }
                                        }
                                        if ($perdido) {
                                            return (false);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return (sha1 ($arqc) === $sha1msg);
    }
    return (false);
}

function obtem_message_id ($fpath) {
    $arqu = fopen ($fpath, "rb");
    if ($arqu !== false) {
        $conteudo = "";
        if (! feof ($arqu)) {
            $blksz = 1024;
            $parte = fread ($arqu, $blksz);
            if ($parte !== false) {
                $conteudo = $parte;
                $pos = strpos ($parte, "\r\n\r\n");
                if ($pos !== false) {
                    $conteudo = substr ($parte, 0, $pos);
                } else if (! feof ($arqu)) {
                    $posbusc = strlen ($parte) - 3;
                    do {
                        $parte = fread ($arqu, $blksz);
                        if ($parte !== false) {
                            $conteudo .= $parte;
                            $pos = strpos ($conteudo, "\r\n\r\n", $posbusc);
                            $posbusc += strlen ($parte);
                            if ($pos !== false) {
                                $conteudo = substr ($conteudo, 0, $pos);
                                break;
                            }
                        } else {
                            break;
                        }
                    } while (! feof ($arqu));
                }
            }
        }
        fclose ($arqu);
        $objcabec = imap_rfc822_parse_headers ($conteudo);
        if (is_object ($objcabec)) {
            if (empty ($objcabec->message_id)) {
                aviso ("#A003 - Impossivel determinar 'Message-ID' da mensagem constante no arquivo '" . try_realpath ($fpath) . "'!");
            } else {
                return ($objcabec->message_id);
            }
        } else {
            aviso ("#A002 - Impossivel analisar cabecalhos do arquivo '" . try_realpath ($fpath) . "'!");
        }
    } else {
        aviso ("#A001 - Impossivel abrir arquivo '" . try_realpath ($fpath) . "'!");
    }
    return (false);
}

function escolhe_nome_arquivo ($mensagem, $rootdir) {
    // Definir um nome de arquivo para a mensagem, pelo assunto
    $nomearquivo = '';
    $pos = strpos ($mensagem, "\r\n\r\n");
    if ($pos !== false) {
        $cabec = substr ($mensagem, 0, $pos);
    } else {
        $cabec = $mensagem;
    }
    $objcabec = imap_rfc822_parse_headers ($cabec);
    if (is_object ($objcabec)) {
        $subj = imap_mime_header_decode ($objcabec->subject);
        if (is_array ($subj)) {
            foreach ($subj as $item) {
                $nomearquivo .= ' ' . $item->text;
            }
        }
        $nomearquivo = trim (preg_replace ("/[^\\w\\[\\]\\(\\)\\{\\}]+/", ' ', $nomearquivo));
    }
    if (empty ($nomearquivo)) {
        $nomearquivo = 'Mensagem de e-mail';
    }
    $ext = '.eml';
    $fname = $nomearquivo . $ext;
    $fpath = $rootdir . '/' . $fname;
    $i = 1;
    clearstatcache (true);
    while (file_exists ($fpath)) {
        $i++;
        $fname = $nomearquivo . ' [' . $i . ']' . $ext;
        $fpath = $rootdir . '/' . $fname;
    }
    return ($fname);
}

//////////////////////////////////////////////////////////////////////

main ();
exibe ("[  Tudo OK.  ]\n");
sai (0);
