-- The school's event evaluation form, Parts I–IV — branch SephChanges
--
-- Data only: no tables or columns change. Safe to run more than once
-- (the second run does nothing).
--
--   1. Hides the current questions instead of deleting them, so evaluations
--      already submitted keep their answers and results. They move to the
--      end of the list in Admin → Evaluation Form, marked "Hidden from the
--      form"; the ones nobody answered can be deleted there if you like.
--   2. Adds the official form's 12 questions in four sections:
--        Part I: Activity Experience ............................ 4 ratings, required
--        Part II: Learner's Takeaway ............................ 3 ratings, required
--        Part III: Proposal & Process Experience
--                  (For Event Committees Only) .................. 3 ratings, optional
--        Part IV: Open Feedback ................................. 2 written answers, required
--
-- Rating scale shown on the form:
--   1 Needs Improvement · 2 Fair · 3 Satisfactory · 4 Very Satisfactory · 5 Excellent

do $$
begin
  if exists (select 1 from public.evaluation_questions where section = 'Part I: Activity Experience') then
    raise notice 'The Parts I–IV evaluation form is already in. Nothing to do.';
    return;
  end if;

  -- 1. Hide the old questions and move them after the new form.
  update public.evaluation_questions
     set is_active = false,
         sort_order = sort_order + 100;

  -- 2. The new form.
  insert into public.evaluation_questions (section, question, question_type, is_required, sort_order) values
    ('Part I: Activity Experience', 'The objectives of the activity were clear and achieved.', 'rating', true, 1),
    ('Part I: Activity Experience', 'The activity was relevant and meaningful to my academic/personal growth.', 'rating', true, 2),
    ('Part I: Activity Experience', 'The speakers/facilitators were effective and engaging.', 'rating', true, 3),
    ('Part I: Activity Experience', 'The activity was well-organized (schedule, venue, logistics, technical support).', 'rating', true, 4),

    ('Part II: Learner''s Takeaway', 'I gained useful knowledge, skills, or insights from this activity.', 'rating', true, 5),
    ('Part II: Learner''s Takeaway', 'I can apply what I learned to my studies, organization, or daily life.', 'rating', true, 6),
    ('Part II: Learner''s Takeaway', 'The activity motivated me to participate in future activities.', 'rating', true, 7),

    ('Part III: Proposal & Process Experience (For Event Committees Only)', 'The process of proposing the activity (SAPF) was clear and manageable.', 'rating', false, 8),
    ('Part III: Proposal & Process Experience (For Event Committees Only)', 'The review and approval process (including online revisions) was efficient.', 'rating', false, 9),
    ('Part III: Proposal & Process Experience (For Event Committees Only)', 'The assistance provided to students with diverse needs.', 'rating', false, 10),

    ('Part IV: Open Feedback', 'What was the most valuable thing you learned from this activity?', 'text', true, 11),
    ('Part IV: Open Feedback', 'What suggestions can you give to improve future activities?', 'text', true, 12);
end $$;
