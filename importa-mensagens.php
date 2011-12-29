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
    exibe (" **** O programa nao foi executado com sucesso! ****\n\n");
    sai (1);
}

function aviso ($msg) {
    exibe ("\n" . $msg . "\n\n", '-- AVISO --');
}

function php_error_handler ($errno, $errstr, $errfile, $errline) {
    aviso ("Erro de PHP: '" . $errfile . "': " . $errline . ": " . $errno . ": " . $errstr . " !");
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
    $local_rootdir = dirname (__FILE__) . '/local-storage';
    $fila[] = array ($thunderbird_dir, $local_rootdir);
    while (($pasta = array_shift ($fila)) !== null) {
        $thund = $pasta[0];
        $locald = $pasta[1];
        $dd = opendir ($thund);
        if (! $dd) {
            morre ("#E011 - Impossivel abrir pasta '" . $pasta . "'!");
        }
        while (($d_entry = readdir ($dd)) !== false) {
            if ($d_entry != '.' && $d_entry != '..') {
                $fpath = $thund . '/' . $d_entry;
                if (is_dir ($fpath)) {
                    if (strtolower (substr ($d_entry, -4)) != '.sbd') {
                        morre ("#E012 - Sintaxe de subpasta invalida: '" . $fpath . "'");
                    }
                    $fila[] = array ($fpath, $locald . '/' . substr ($d_entry, 0, strlen ($d_entry) - 4));
                } else if (is_file ($fpath)) {
                    $pos = strpos ($d_entry, '.');
                    if ($pos === false) {
                        exibe ("Abrindo '" . $fpath . "'...");
                        $conta_importados = 0;
                        $conta_repetidos = 0;
                        $filedir = $locald . '/' . $d_entry;
                        if (! is_dir ($filedir)) {
                            if (! mkdir ($filedir, 0755, true)) {
                                morre ("#E013 - Impossivel criar pasta local '" . $filedir . "'!");
                            }
                        }
                        // Estou com problema para tratar casos onde o sistema de arquivos eh sensivel a maiusculas e minusculas e
                        // casos onde o sistema de arquivos eh insensivel. Por isso, vou reler a pasta e os arquivos...
                        // Menos desempenho, mais confiabilidade...
                        $jabaixou = array ();
                        $dd2 = opendir ($filedir);
                        if (! $dd2) {
                            morre ("#E015 - Impossivel abrir pasta '" . $filedir . "'!");
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
                            morre ("#E017 - Impossivel abrir arquivo '" . $fpath . "' para leitura!");
                        }
                        $fila_leit = array ();
                        for ($i = 0; $i < 3; $i++) {
                            $linha = fgets ($fd);
                            if ($linha !== false) {
                                $fila_leit[] = $linha;
                            } else {
                                morre ("#E019 - EOF prematuro durante leitura do arquivo '" . $fpath . "'!");
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
                                                    morre ("#E021 - Erro de leitura na linha #" . $cntlinha . " do arquivo '" . $fpath . "'!");
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
                                                morre ("#E022 - Erro detectando limite de mensagem na linha #" . $cntlinha . " do arquivo '" . $fpath . "'!");
                                            } else {
                                                $msg_email .= $linha;
                                            }
                                        }
                                        $objcabec = imap_rfc822_parse_headers ($msg_email);
                                        if (! is_object ($objcabec)) {
                                            morre ("#E023 - Falha ao analisar cabecalhos da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . $fpath . "'!");
                                        }
                                        if (empty ($objcabec->message_id)) {
                                            morre ("#E024 - Falha ao identificar ID da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . $fpath . "'!");
                                        }
                                        if (array_key_exists ($objcabec->message_id, $jabaixou)) {
                                            $conta_repetidos++;
                                            exibe (':');
                                            $f2path = $jabaixou[$objcabec->message_id];
                                            if (! compara_mensagens ($msg_email, $f2path)) {
                                                morre ("#E025 - Verificacao de integridade falhou entre o arquivo '" . $f2path . "' e a mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . $fpath . "'!");
                                            }
                                        } else {
                                            $nomenovo = escolhe_nome_arquivo ($msg_email, $filedir);
                                            $caminhonovo = $filedir . '/' . $nomenovo;
                                            $bytes = file_put_contents ($caminhonovo, $msg_email);
                                            if ($bytes === false) {
                                                morre ("#E026 - Impossivel gravar arquivo '" . $caminhonovo . "' com o conteudo da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . $fpath . "'!");
                                            } else if (! compara_mensagens ($msg_email, $caminhonovo, true)) {
                                                morre ("#E027 - Falha na comparacao do conteudo do arquivo '" . $caminhonovo . "' com o conteudo da mensagem contida entre as linhas #" . $msg_inicio . " e #" . ($cntlinha - 1) . " do arquivo '" . $fpath . "'!\n\nSera disco cheio ou danificado?");
                                            } else {
                                                exibe ('*');
                                                $conta_importados++;
                                                $jabaixou[$objcabec->message_id] = $caminhonovo;
                                            }
                                        }
                                    } else {
                                        morre ("#E020 - Erro detectando cabecalho 'X-Mozilla-Status2' na linha #" . $cntlinha . " do arquivo '" . $fpath . "'!");
                                    }
                                } else {
                                    morre ("#E019 - Erro detectando cabecalho 'X-Mozilla-Status' na linha #" . $cntlinha . " do arquivo '" . $fpath . "'!");
                                }
                            } else {
                                morre ("#E018 - Erro detectando cabecalho 'From - ' na linha #" . $cntlinha . " do arquivo '" . $fpath . "'!");
                            }
                        }
                        fclose ($fd);
                        exibe ("\n +++ " . $conta_importados . " extraido + " . $conta_repetidos . " duplicado!\n");
                    } else if ($d_entry != 'msgFilterRules.dat') { // Arquivo que deve ser ignorado...
                        $ext = strtolower (substr ($d_entry, $pos));
                        if ($ext != '.msf') {
                            morre ("#E014 - Encontrado arquivo estranho na pasta de mensagens do Thunderbird: '" . $fpath . "'!");
                        }
                    }
                }
            }
        }
        closedir ($dd);
        clearstatcache (true);
    }
    
    // Remontar os arquivos MailBox e destruir os arquivos '*.msf'
    
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
    /* Apos os testes de "parse" nos arquivos do Thunderbird, remover ou comentar esta linha */ return;
    exibe ("Explorando pasta '" . $folder . "'...");

    $rootdir = dirname (__FILE__) . '/local-storage/' . str_replace ('.', '/', $folder);
    if (! is_dir ($rootdir)) {
        if (! mkdir ($rootdir, 0755, true)) {
            morre ("#E004 - Impossivel criar pasta local '" . $rootdir . "'!");
        }
    }
    // Obter mensagens EML da pasta 
    $jabaixou = array ();
    $dd = opendir ($rootdir);
    if (! $dd) {
        morre ("#E009 - Impossivel abrir pasta '" . $rootdir . "'!");
    }
    while (($d_entry = readdir ($dd)) !== false) {
        if ($d_entry != '.' && $d_entry != '..' && strtolower (substr ($d_entry, -4)) == '.eml') {
            $fpath = $rootdir . '/' . $d_entry;
            if (is_file ($fpath)) {
                $msgid = obtem_message_id ($fpath);
                if ($msgid !== false) {
                    if (in_array ($msgid, $jabaixou)) {
                        // Mensagem duplicada na pasta ???
                        unlink ($fpath);
                        exibe ("'");
                    } else {
                        $jabaixou[] = $msgid;
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
            $cabec = imap_fetchheader ($imapconn, $mailitem, FT_UID);
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
            $faz_remocao = false;
            // A mensagem eh repetida?
            if (in_array ($objcabec->message_id, $jabaixou)) {
                exibe ('-');
                $conta_repetidos++;
                $faz_remocao = true;
            } else {
                // Senao, observar o corpo...
                $corpo = imap_body ($imapconn, $mailitem, FT_UID | FT_PEEK);
                if (empty ($corpo) && $corpo !== '') {
                    morre ("#E006 - Impossivel ler o corpo da mensagem #" . $mailitem . " da pasta '" . $folder . "': " . imap_last_error ());
                }
                $fname = escolhe_nome_arquivo ($cabec, $rootdir);
                $fpath = $rootdir . '/' . $fname;
                $todamensagem = $cabec . $corpo;
                $bytes = file_put_contents ($fpath, $todamensagem);
                if ($bytes === false) {
                    aviso ("#A005 - Impossivel gravar arquivo '" . $fpath . "' com o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'!");
                } else if (! compara_mensagens ($todamensagem, $fpath, true)) {
                    aviso ("#A006 - Falha na comparacao do conteudo do arquivo '" . $fpath . "' com o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'!\n\nSera disco cheio ou danificado?");
                } else {
                    exibe ('.');
                    $jabaixou[] = $objcabec->message_id;
                    $conta_importados++;
                    $faz_remocao = true;
                }
            }
            if ($faz_remocao) {
                // Apagar a mensagem do servidor, desde que nao se esteja explorando a pasta 'Acesso' ou alguma das pastas 'atd - *'...
                // CUIDADO: "Atd" tambem nao pode ser apagado!
                if (strpos ($folder, '.Acesso.') === false && substr ($folder, -7) != '.Acesso' && strpos ($folder, '.atd - ') === false) {
                    // imap_delete ($imapconn, $mailitem, FT_UID);
                }
            }
        }
        
        exibe ("\n +++ " . $conta_importados . " importado + " . $conta_repetidos . " repetido!\n");
    } else {
        exibe (" Nada para importar.\n");
    }
}

function compara_mensagens ($mensagem, $arquivo, $byteabyte = false) {
    // Funcao que compara o conteudo de uma mensagem com o conteudo de um arquivo
    // Se $byteabyte = true, a verificacao sera menos relaxada...
    $sha1msg = sha1 ($mensagem);
    $arqc = file_get_contents ($arquivo);
    if ($arqc !== false) {
        if (! $byteabyte) {
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
                aviso ("#A003 - Impossivel determinar 'Message-ID' da mensagem constante no arquivo '" . $fpath . "'!");
            } else {
                return ($objcabec->message_id);
            }
        } else {
            aviso ("#A002 - Impossivel analisar cabecalhos do arquivo '" . $fpath . "'!");
        }
    } else {
        aviso ("#A001 - Impossivel abrir arquivo '" . $fpath . "'!");
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
exibe ("[ OK ]\n");
sai (0);
