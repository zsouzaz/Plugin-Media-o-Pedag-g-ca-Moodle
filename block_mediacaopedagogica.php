<?php
class block_mediacaopedagogica extends block_base
{
    public function init()
    {
        $this->title = get_string('pluginname', 'block_mediacaopedagogica');
    }

    public function applicable_formats()
    {
        // Define onde o bloco pode ser usado
        return array(
            'course-view' => true, // Disponível na página do curso
            'site' => false,       // Não disponível na página principal
        );
    }

    public function get_content()
    {
        global $COURSE, $DB, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        $PAGE->requires->css(new moodle_url('/blocks/mediacaopedagogica/style.css'));

        // Capturar o ID do banco selecionado
        $banco_id = optional_param('banco_id', 0, PARAM_INT);

        $bancos = $this->get_bancos_disponiveis($COURSE->id);

        if (empty($bancos)) {
            $this->content->text = '<p>Não há bases de dados disponíveis neste curso.</p>';
            return $this->content;
        }

        $this->content->text = $this->render_dropdown($bancos, $banco_id);

        if ($banco_id) {
            $atividades = $this->get_atividades($banco_id);

            if (empty($atividades)) {
                $this->content->text .= '<p>Não há atividades cadastradas ou vencidas nesta base de dados.</p>';
            } else {
                $this->content->text .= $this->render_atividades($atividades);
            }
        }

        return $this->content;
    }

    private function get_bancos_disponiveis($courseid)
    {
        global $DB;

        // Buscar as bases de dados (tabela mdl_data) do curso
        return $DB->get_records('data', ['course' => $courseid], 'name ASC', 'id, name');
    }

    private function render_dropdown($bancos, $banco_id)
    {
        global $COURSE;

        $html = '<form method="get">';
        $html .= '<label for="banco_id">Selecione a Base de Dados:</label>';

        $html .= '<input type="hidden" name="id" value="' . $COURSE->id . '">';

        $html .= '<select name="banco_id" id="banco_id" onchange="this.form.submit()">';

        $html .= '<option value="0">-- Selecione --</option>';

        foreach ($bancos as $banco) {
            $selected = ($banco->id == $banco_id) ? 'selected' : '';
            $html .= "<option value='{$banco->id}' {$selected}>{$banco->name}</option>";
        }

        $html .= '</select>';
        $html .= '</form>';

        // Construir o link correto para a base de dados selecionada
        if ($banco_id) {
            $url = $this->get_activity_url($banco_id, $COURSE->id);
            if ($url) {
                $html .= "<p id='verBaseDeDados'><a href='{$url}' target='_blank'>Ver toda a base de dados</a></p>";
            } else {
                $html .= '<p>Erro ao gerar o link para a base de dados.</p>';
            }
        }

        return $html;
    }

    private function get_activity_url($banco_id, $course_id)
    {
        global $DB;

        // Obter todas as informações das atividades do curso
        $modinfo = get_fast_modinfo($course_id);
        if (!$modinfo) {
            return null;
        }

        foreach ($modinfo->instances['data'] as $instance) {
            if ($instance->instance == $banco_id) {
                return new moodle_url($instance->url);
            }
        }
        return null;
    }


    private function get_atividades($banco_id)
    {
        global $DB;

        if (!$banco_id) {
            return [];
        }

        $sql = "
            SELECT 
                dc1.recordid,
                MAX(CASE WHEN df.name = 'Atividade' THEN dc1.content ELSE NULL END) AS atividade,
                MAX(CASE WHEN df.name = 'Data' THEN dc1.content ELSE NULL END) AS data,
                MAX(CASE WHEN df.name = 'Feito' THEN dc1.content ELSE NULL END) AS feito,
                MAX(CASE WHEN df.name = 'Tipo de mensagem' THEN dc1.content ELSE NULL END) AS tipo_mensagem,
                MAX(CASE WHEN df.name = 'Feito' THEN dc1.id ELSE NULL END) AS contentid
            FROM 
                {data_content} dc1
            JOIN 
                {data_fields} df ON dc1.fieldid = df.id
            JOIN 
                {data} d ON df.dataid = d.id
            WHERE 
                d.id = ? 
                AND dc1.recordid IN (
                    SELECT recordid
                    FROM {data_content} dc2
                    JOIN {data_fields} df2 ON dc2.fieldid = df2.id
                    WHERE df2.name = 'Feito' AND dc2.content = 'Não'
                )
            GROUP BY 
                dc1.recordid
            ORDER BY 
                data ASC
        ";

        return $DB->get_records_sql($sql, [$banco_id]);
    }


    private function render_atividades($atividades)
    {
        $template_path = __DIR__ . '/atividade-lista.html';

        if (!file_exists($template_path)) {
            return '<p>Erro: Template não encontrado.</p>';
        }

        $template_content = file_get_contents($template_path);
        $html = '<div class="plugin-mediacao">';

        foreach ($atividades as $atividade) {
            // Verifica se o campo 'data' está presente e válido
            if (empty($atividade->data)) {
                $html .= '<p>Erro: Data ausente para a atividade: ' . htmlspecialchars($atividade->atividade ?? 'Atividade sem nome') . '</p>';
                continue;
            }

            // Converte o campo 'data' para timestamp
            $timestamp = is_numeric($atividade->data) ? (int) $atividade->data : strtotime($atividade->data);

            if (!$timestamp || $timestamp <= 0) {
                $html .= '<p>Erro: Formato de data inválido para a atividade: ' . htmlspecialchars($atividade->atividade ?? 'Atividade sem nome') . '</p>';
                continue;
            }

            $data = (new DateTime())->setTimestamp($timestamp);
            $hoje = new DateTime();
            $status = '';

            // Determina o status da atividade
            if ($data < $hoje) {
                $status = '<span class="atrasada">Atrasada</span>';
            } else {
                $dias = $hoje->diff($data)->days;
                $status = "<span class='dias-restantes'>{$dias} Dias</span>";
            }

            $descricao_url = new moodle_url('/mod/data/view.php', [
                'rid' => $atividade->recordid, // ID do registro
                'filter' => 1
            ]);

            // Substituir os placeholders no template
            $item = str_replace(
                ['{DATA}', '{STATUS}', '{TIPO_MENSAGEM}', '{DESCRICAO}', '{ID}'],
                [
                    $data->format('d/m/Y'),
                    $status,
                    htmlspecialchars($atividade->tipo_mensagem ?? 'Sem tipo de mensagem'),
                    "<a href='{$descricao_url}' target='_blank'>" . htmlspecialchars($atividade->atividade ?? 'Sem descrição') . "</a>",
                    htmlspecialchars($atividade->contentid ?? 'Sem ID')
                ],
                $template_content
            );

            $html .= $item;
        }

        $html .= '</div>';

        // JavaScript para envio AJAX
        $html .= '
        <script>
            document.addEventListener(\'DOMContentLoaded\', () => {
                document.querySelectorAll(\'.btn-finalizar\').forEach(button => {
                    button.addEventListener(\'click\', () => {
                        if (confirm(\'Tem certeza que deseja finalizar essa tarefa?\')) {
                        const id = button.getAttribute(\'data-id\');

                    console.log(`Finalizando tarefa com ID: ${id}`);

                    fetch(\'http://localhost/blocks/mediacaopedagogica/finalizar_tarefa.php\', {
                        method: \'POST\',
                        headers: {
                            \'Content-Type\': \'application/json\'
                        },
                        body: JSON.stringify({ id })
                    })
                    .then(response => {
                        console.log(\'Resposta recebida:\', response);

                        if (response.headers.get(\'Content-Type\').includes(\'application/json\')) {
                            return response.json();
                        } else {
                            throw new Error(\'Resposta não é JSON válida\');
                        }
                    })
                    .then(data => {
                        console.log(\'Dados recebidos:\', data);

                        if (data.success) {
                            alert(\'Tarefa finalizada com sucesso!\');
                            location.reload(); // Recarrega a página
                        } else {
                            alert(`Erro: ${data.message}`);
                        }
                    })
                    .catch(error => {
                        console.error(\'Erro na requisição ou no processamento:\', error);
                        alert(\'Erro na conexão com o servidor ou resposta inválida. Verifique os logs.\');
                    });
                }
                });
            });
        });
        </script>';
        return $html;
    }
}