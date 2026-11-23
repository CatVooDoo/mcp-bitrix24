<?php
declare(strict_types=1);
namespace Bitrix24Mcp\Services;

use Bitrix24Mcp\Core\{Env,Policy};

/** Trusted owner rules and a small relevant slice of the supplied catalogs. */
final class SupportKnowledge
{
    private static function read(string $file): array
    {
        return json_decode((string)file_get_contents(Env::root().'/config/'.$file),true,512,JSON_THROW_ON_ERROR);
    }
    public static function rules(): string
    {
        $rules=file_get_contents(Env::root().'/SUPPORT_AGENT_RULES.md');
        if ($rules===false || trim($rules)==='') { throw new \RuntimeException('Owner support rules are required'); }
        $style=file_get_contents(Env::root().'/config/reply-style.md');
        if ($style===false) { throw new \RuntimeException('Reply style rules are required'); }
        return $rules."\n\n".$style;
    }
    public static function context(array $case): array
    {
        $catalog=self::read('service_catalog.json');$projects=self::read('projects_catalog.json')['projects'];
        $text=implode("\n",array_column($case['data']['messages'] ?? [],'text'));$matched=[];
        foreach ($projects as $key=>$project) {
            $terms=array_filter(array_merge([$key,$project['name'] ?? '',$project['domain'] ?? '',$project['group_name'] ?? ''],$project['aliases'] ?? []));
            foreach ($terms as $term) {
                if (mb_stripos($text,$term)!==false) { $matched[$key]=$project;break; }
            }
        }
        return ['company'=>$catalog['company'],'consultation_policy'=>$catalog['consultation_policy'],'projects'=>$matched];
    }
    public static function taskFields(array $case,array $fields,Policy $policy): array
    {
        $knowledge=self::context($case);$projects=array_values($knowledge['projects']);
        $project=count($projects)===1 ? $projects[0] : null;
        $group=(int)($project['group_id'] ?? $fields['group_id'] ?? $knowledge['company']['default_group_id']);
        $groupName=$project['group_name'] ?? $fields['project_group_name'] ?? $knowledge['company']['default_group_name'];
        $now=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Moscow'));
        $due=$now->modify('+'.max(60,(int)$policy->get('employee_timeout_seconds',3600)).' seconds');
        // Owner policy fixes the escalation assignee, regardless of old tasks
        // or a model-supplied employee ID.
        $employee=(int)$policy->get('default_responsible_id');
        if (!$policy->employee($employee)) { throw new \DomainException('Task responsible must be an allowed employee'); }
        $fields['group_id']=$group;$fields['project_group_name']=$groupName;$fields['responsible_id']=$employee;
        $fields['title']=$groupName.' | '.($fields['task_summary'] ?? 'Разобрать обращение клиента ['.$case['data']['dialog_id'].']').' | '.$now->format('d.m.Y');
        $fields['deadline']=$fields['deadline'] ?? $due->format(DATE_ATOM);
        $fields['time_estimate_minutes']=$fields['time_estimate_minutes'] ?? 15;
        $messages=$case['data']['messages'] ?? [];$last=$messages ? $messages[array_key_last($messages)] : [];
        $fields['description']='[USER='.$employee.']Ответственный менеджер[/USER]' . "\n" . ($fields['description'] ?? '')."\nОбращение клиента: ".($last['text'] ?? '')
            ."\nКанал: ".$case['data']['dialog_id']."\nДата: ".$now->format(DATE_ATOM)
            ."\nУже сообщено клиенту: ".($case['data']['last_reply_text'] ?? 'См. исходный диалог; краткая запись отсутствует.')
            ."\nПлан: уточнить детали и материалы; определить дальнейшие работы; записать проверенный ответ для клиента в задаче."
            ."\nСрок обратной связи: ".$fields['deadline']." (план, не подтверждённый SLA и не срок выполнения работ)."
            ."\nПри отсутствии отдельной оценки 15 минут относятся к первичному разбору обращения, а не ко всем запрошенным работам.";
        return $fields;
    }
}
