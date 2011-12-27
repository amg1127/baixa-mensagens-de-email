<?php

$line_is_blank = true;

function exibe ($msg, $prefixo = "") {
    global $line_is_blank;
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
}

function prompt ($msg) {
    global $line_is_blank;
    exibe ($msg);
    $resp = trim (fgets (STDIN));
    $line_is_blank = true;
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

    // Varrer cada pasta e importar mensagens
    $lenimapserver = strlen ($imapserver);
    $folder_importar = array ();
    foreach ($folderlist as $folder) {
        if (substr ($folder, 0, $lenimapserver) != $imapserver) {
            morre ("#E003 - Sintaxe de pasta invalida: '" . $folder . "'");
        } else {
            $folder = substr ($folder, $lenimapserver);
            if ($folder == 'user.outro-usuario' || substr ($folder, 0, 19) == 'user.outro-usuario.') {
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
                        exibe ("Abrindo '" . $fpath . "'...\n");
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
                        # CONTINUAR DAQUI!
                        fclose ($fd);
                    } else {
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

function importa_pasta ($imapconn, $imapserver, $folder) {
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
                } else if (sha1 ($todamensagem) !== sha1_file ($fpath)) {
                    aviso ("#A006 - Falha na comparacao do conteudo do arquivo '" . $fpath . "' com o conteudo da mensagem #" . $mailitem . " da pasta '" . $folder . "'!");
                } else {
                    exibe ('.');
                    $jabaixou[] = $objcabec->message_id;
                    $conta_importados++;
                    if (! ($conta_importados % 20)) {
                        exibe ('^');
                    }
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
        
        exibe (" " . $conta_importados . " importado + " . $conta_repetidos . " repetido!\n");
    } else {
        exibe (" Nada para importar.\n");
    }
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
