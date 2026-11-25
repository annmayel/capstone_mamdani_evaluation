<?php

function proposal_criteria(): array
{
    return [
        'PFO1.1' => ['key'=>'pfo1_1','comment_key'=>'pfo1_1_comment','chapter'=>'Chapter 1: Problem Formulation and Objectives','label'=>'Introduction and Project Context','description'=>'Clearly defines the industry/sector overview and establishes a valid, real-world problem with properly cited current pain points.','maximum'=>10],
        'PFO1.2' => ['key'=>'pfo1_2','comment_key'=>'pfo1_2_comment','chapter'=>'Chapter 1: Problem Formulation and Objectives','label'=>'Objectives','description'=>'General and Specific Objectives are measurable, achievable, and logically mapped to the development phases.','maximum'=>10],
        'PFO1.3' => ['key'=>'pfo1_3','comment_key'=>'pfo1_3_comment','chapter'=>'Chapter 1: Problem Formulation and Objectives','label'=>'Scope and Significance','description'=>'Boundaries and constraints are clearly set. Beneficiaries are well-identified and mapped to a specific SDG or University Research Agenda.','maximum'=>10],
        'LTS2.1' => ['key'=>'lts2_1','comment_key'=>'lts2_1_comment','chapter'=>'Chapter 2: Literature and Theoretical Soundness','label'=>'RRL and RRS','description'=>'Academic literature and existing systems are highly relevant, current (last 5 years), and comprehensively analyzed.','maximum'=>10],
        'LTS2.2' => ['key'=>'lts2_2','comment_key'=>'lts2_2_comment','chapter'=>'Chapter 2: Literature and Theoretical Soundness','label'=>'Synthesis and Research Gap','description'=>'The proponents successfully synthesized the reviews and explicitly proved a valid Research Gap that their project will address.','maximum'=>5],
        'LTS2.3' => ['key'=>'lts2_3','comment_key'=>'lts2_3_comment','chapter'=>'Chapter 2: Literature and Theoretical Soundness','label'=>'Theoretical/Conceptual and Technical Background','description'=>'Methodological soundness is proven via a clear IPO diagram, established scientific theories, and a solid understanding of the chosen modern components/technologies.','maximum'=>5],
        'MSA3.1' => ['key'=>'msa3_1','comment_key'=>'msa3_1_comment','chapter'=>'Chapter 3: Methodology and System Architecture','label'=>'Research Design and Development Phases','description'=>'Appropriate research approach (e.g., SDLC for IT, Experimental/Engineering Design for BSCpE/BSEE) is justified and detailed step-by-step.','maximum'=>10],
        'MSA3.2' => ['key'=>'msa3_2','comment_key'=>'msa3_2_comment','chapter'=>'Chapter 3: Methodology and System Architecture','label'=>'Technical Description and Architecture','description'=>'Visual blueprints (ERDs, Schematics, AI models, UI/UX wireframes, etc.) are logically sound. Hardware/Software requirements are fully itemized.','maximum'=>20],
        'MSA3.3' => ['key'=>'msa3_3','comment_key'=>'msa3_3_comment','chapter'=>'Chapter 3: Methodology and System Architecture','label'=>'Requirements and Mathematics/Statistics','description'=>'Functional and Non-Functional Requirements are distinct and clear. The mathematical foundation (load computations/algorithms) and Statistical Treatment for future testing are correct.','maximum'=>10],
        'PPR4.1' => ['key'=>'ppr4_1','comment_key'=>'ppr4_1_comment','chapter'=>'Chapter 4: Oral Presentation and Proposal Readiness','label'=>'Defense and Justification','description'=>"Ability to confidently answer the panel's technical queries and successfully justify their chosen architecture and methodology.",'maximum'=>5],
        'PPR4.2' => ['key'=>'ppr4_2','comment_key'=>'ppr4_2_comment','chapter'=>'Chapter 4: Oral Presentation and Proposal Readiness','label'=>'Delivery, Mastery, and Manuscript Compliance','description'=>'Proponents demonstrated professional oral delivery and a deep understanding of the proposed solution and the printed manuscript strictly adheres to IEEE formatting, grammatical standards, academic integrity checks, and includes all required CEIT forms and appendices.','maximum'=>5],
    ];
}

function proposal_evaluation_schema_available(PDO $pdo): bool
{
    try {
        return $pdo->query("SHOW TABLES LIKE 'proposal_evaluations'")->fetchColumn() !== false;
    } catch (PDOException $exception) {
        error_log('[proposal evaluation] Schema availability check failed: ' . $exception->getMessage());
        return false;
    }
}

function proposal_validate_scores(array $input, bool $requireComplete = true): array
{
    $scores=[]; $errors=[];
    foreach (proposal_criteria() as $criterion) {
        $key=$criterion['key']; $raw=trim((string)($input[$key]??''));
        if ($raw==='') { if ($requireComplete) $errors[]=$criterion['label'].' score is required.'; else $scores[$key]=null; continue; }
        if (!preg_match('/^(?:\d+(?:\.\d{1,2})?)$/',$raw)) { $errors[]=$criterion['label'].' score must be a non-negative number with up to two decimal places.'; continue; }
        $value=(float)$raw;
        if (!is_finite($value)||$value>(float)$criterion['maximum']) { $errors[]=$criterion['label'].' score must be between 0 and '.$criterion['maximum'].'.'; continue; }
        $scores[$key]=round($value,2);
    }
    $total=round(array_sum(array_map(static fn($score)=>(float)($score??0),$scores)),2);
    return ['scores'=>$scores,'total'=>$total,'percentage'=>$total,'complete'=>count(array_filter($scores,static fn($score)=>$score!==null))===count(proposal_criteria()),'errors'=>$errors];
}

function proposal_consolidated(PDO $pdo,int $projectId,int $submissionId): array
{
    $criteria=proposal_criteria(); $columns=array_column($criteria,'key');
    $sql='SELECT * FROM proposal_evaluations WHERE project_id=? AND proposal_submission_id=? AND status=? AND '.implode(' AND ',array_map(static fn($column)=>$column.' IS NOT NULL',$columns)).' ORDER BY id';
    $stmt=$pdo->prepare($sql); $stmt->execute([$projectId,$submissionId,'submitted']); $evaluations=$stmt->fetchAll(PDO::FETCH_ASSOC);
    $averages=array_fill_keys(array_keys($criteria),null);
    if ($evaluations) foreach ($criteria as $code=>$criterion) $averages[$code]=round(array_sum(array_map(static fn($row)=>(float)$row[$criterion['key']],$evaluations))/count($evaluations),2);
    $score=$evaluations?round(array_sum($averages),2):null;
    return ['evaluations'=>$evaluations,'criteria'=>$averages,'calculated_score'=>$score,'evaluator_count'=>count($evaluations),'recommendation'=>$score===null?null:proposal_verdict_from_score($score)];
}

function proposal_verdict_bands(): array { return official_verdict_definitions(); }

function proposal_verdict_from_score(float $score): array
{
    $trace=build_fuzzy_trace($score,$score); $verdict=$trace['verdict'];
    return ['label'=>$verdict['label'],'description'=>$verdict['description'].' '.$verdict['next_step'],'percentage'=>round($score,2),'fuzzy'=>$trace];
}
