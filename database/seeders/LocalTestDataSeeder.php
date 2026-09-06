<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Episodes\MakeEpisodeReady;
use App\Application\Episodes\Readiness\AssessEpisodeReadiness;
use App\Application\Episodes\Readiness\EpisodeReadiness;
use App\Enums\AiPersonaStatus;
use App\Enums\EpisodeStatus;
use App\Enums\ShowStatus;
use App\Models\AiPersona;
use App\Models\Episode;
use App\Models\EpisodeQuestion;
use App\Models\Show;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * LOCAL DEVELOPMENT ONLY — realistic manual-test data for the admin panel and
 * the Episode Preparation Workspace (TASK-0001 … TASK-0004).
 *
 * NOT registered in DatabaseSeeder — it has no effect unless invoked explicitly:
 *
 *     php artisan db:seed --class=LocalTestDataSeeder
 *
 * Idempotent: re-running creates no duplicate Show / AiPersona / Episode /
 * Topic / Question. It uses the real models, enums, DB constraints and the
 * readiness service, and reaches "Ready" only through the guarded
 * MakeEpisodeReady application service — never a direct status write.
 */
class LocalTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $show = $this->seedShow();
        $persona = $this->seedPersona();
        $episode = $this->seedEpisode($show);

        $this->seedLineup($episode, $persona);
        $topicCount = $this->seedTopicsAndQuestions($episode);

        $episode->refresh();

        $readiness = app(AssessEpisodeReadiness::class)($episode);
        $this->reportReadiness($readiness);

        if ($readiness->isReady()) {
            app(MakeEpisodeReady::class)($episode);
            $episode->refresh();
        }

        $questionCount = EpisodeQuestion::query()
            ->whereIn('episode_topic_id', $episode->topics()->select('id'))
            ->count();

        $this->reportSummary($show, $persona, $episode, $topicCount, $questionCount, $readiness->isReady());
    }

    private function seedShow(): Show
    {
        return Show::updateOrCreate(
            ['slug' => 'gercegin-pesinde'],
            [
                'name' => 'Gerçeğin Peşinde',
                'description' => <<<'TEXT'
                    Tarih, toplum, inanç ve insan hayatına dair önemli meselelerin kaynaklar, farklı görüşler ve tarihsel bağlam çerçevesinde ele alındığı haftalık müzakere programı. Gerçek bir sunucu ile yapay zekâ karakteri karşılıklı konuşarak konuyu farklı yönleriyle tartışır.
                    TEXT,
                'status' => ShowStatus::Active,
            ],
        );
    }

    private function seedPersona(): AiPersona
    {
        return AiPersona::updateOrCreate(
            ['name' => 'Hikmet'],
            [
                'title' => 'Tarih ve Medeniyet Araştırmacısı',
                'biography' => <<<'TEXT'
                    Tarih, medeniyet, dinler tarihi, toplumsal yapı ve düşünce tarihi alanlarında konuları kaynak merkezli ele almak üzere tasarlanmış yapay zekâ müzakere karakteri.
                    TEXT,
                'expertise' => <<<'TEXT'
                    İslam tarihi, İslam öncesi Arap toplumu, dinler tarihi, sosyal tarih, kadın tarihi, hukuk tarihi, medeniyetler tarihi ve tarihsel kaynak değerlendirmesi.
                    TEXT,
                'personality' => <<<'TEXT'
                    Sakin, ölçülü, araştırmacı, saygılı ve analitik. Tartışmayı kazanmayı değil meseleyi açıklığa kavuşturmayı amaçlar. Kesinliği tartışmalı tarihsel iddiaları kesin gerçek gibi sunmaz.
                    TEXT,
                'speaking_style' => <<<'TEXT'
                    Televizyon programına uygun, açık ve doğal Türkçe kullanır. Önce soruya doğrudan cevap verir, ardından gerektiğinde tarihsel bağlam ekler. Uzun akademik monologlardan kaçınır. Sunucunun karşı argümanlarına cevap verir ve gerektiğinde farklı tarihsel yorumların bulunduğunu açıkça belirtir.
                    TEXT,
                'system_prompt' => <<<'TEXT'
                    Senin adın Hikmet. Gerçeğin Peşinde adlı televizyon programında gerçek bir sunucuyla tarih, medeniyet, toplum ve din konularını müzakere eden yapay zekâ karakterisin.

                    Amacın bir tartışmayı kazanmak değil, konuyu kaynaklar ve tarihsel bağlam üzerinden anlaşılır biçimde değerlendirmektir.

                    Tarihsel konularda:

                    - Kaynakların niteliğini ve dönem farklılıklarını gözet.
                    - Tartışmalı veya genellenmesi zor iddiaları kesin gerçek gibi sunma.
                    - İslam öncesi Arabistan'ı tek tip bir toplum olarak göstermemeye dikkat et.
                    - Farklı kabile, bölge, sınıf ve dönemlerde farklı uygulamalar bulunabileceğini gerektiğinde belirt.
                    - Dini metin ile tarihsel uygulamayı birbirinden ayır.
                    - Kur'an ayetleri veya dini hükümlerden bahsederken anlamı bağlamından koparma.
                    - Modern değer yargılarını geçmişe doğrudan yansıtmaktan kaçın.
                    - Kaynağı belirsiz popüler anlatıları gerçekmiş gibi tekrarlama.

                    Cevapların çoğunlukla 30-60 saniyelik televizyon konuşmasına uygun olsun.
                    Gerektiğinde sunucunun iddiasına karşı gerekçeli bir itiraz sun.
                    Bilmediğin veya tarihçiler arasında ihtilaflı olan bir konuda bunu açıkça söyle.
                    TEXT,
                // Logical keys only — all present in config('ai.persona.*') allow-lists.
                'ai_provider' => 'default',
                'ai_model' => 'default',
                'voice_provider' => 'default',
                'voice_id' => 'analyst',
                'screen_settings' => [
                    'display_name' => 'Hikmet',
                    'display_title' => 'Tarih ve Medeniyet Araştırmacısı',
                    'avatar_position' => 'right',
                    'avatar_size' => 100,
                    'lower_third_enabled' => true,
                ],
                'status' => AiPersonaStatus::Active,
            ],
        );
    }

    private function seedEpisode(Show $show): Episode
    {
        $episode = Episode::query()->firstOrNew([
            'show_id' => $show->id,
            'episode_number' => 1,
        ]);

        $episode->fill([
            'title' => 'Cahiliye Döneminden İslam\'a: Kadının Toplumdaki Yeri',
            'main_topic' => <<<'TEXT'
                İslam öncesi Arap toplumlarında kadının konumu ile İslam'ın getirdiği sosyal, hukuki ve ahlaki düzenlemelerin karşılaştırmalı olarak değerlendirilmesi.
                TEXT,
            'purpose' => <<<'TEXT'
                Programın amacı, "İslam öncesinde kadının hiçbir hakkı yoktu" veya bunun tam tersi gibi aşırı genellemeler yerine, dönemin sosyal yapısını tarihsel bağlamıyla ele almak ve İslam'ın kadınlara ilişkin getirdiği miras, mülkiyet, evlilik, aile ve insan onuru merkezli düzenlemelerin ne anlama geldiğini müzakere etmektir.
                TEXT,
            'preparation_notes' => <<<'TEXT'
                Program tartışma formatında ilerleyecek. Sunucu yer yer yaygın iddiaları güçlü şekilde dile getirecek, Hikmet ise bunları tarihsel bağlam, kaynakların niteliği ve İslam'ın normatif düzenlemeleri üzerinden değerlendirecek.

                Özellikle "cahiliye dönemi" ifadesinin bütün Arap toplumunu, bütün kabileleri ve bütün kadınları tek kalıba sokmadığı vurgulanmalı.

                Programın amacı polemik üretmek değil; tarihsel gerçeklik, dini metin ve sonraki toplumsal uygulama arasındaki farkları anlaşılır biçimde ortaya koymaktır.
                TEXT,
            'broadcast_instructions' => <<<'TEXT'
                - Sunucu ile Hikmet arasında gerçek bir müzakere hissi oluştur.
                - İlk cevaplar mümkün olduğunca 30-60 saniye arasında olsun.
                - Sunucu gerektiğinde itiraz etsin veya karşı örnek sorsun.
                - Hikmet her soruya uzun ders anlatımıyla başlamasın.
                - Kritik tarihsel iddialarda "hangi kaynaklara dayanıyoruz?" sorusuna alan aç.
                - Kur'an'dan söz edildiğinde ayet numarası veya konu başlığı belirtilebilir; ancak uzun alıntılar yapılmasın.
                - Tarihsel uygulama ile İslam'ın normatif hükmü karıştırılmasın.
                - Bugünkü Müslüman toplumların uygulamaları otomatik olarak İslam'ın ilkeleriyle özdeşleştirilmesin.
                TEXT,
            // Presenter brief ("Sunucu Brifingi")
            'opening_notes' => <<<'TEXT'
                Bugün çok konuşulan ama çoğu zaman sloganlar üzerinden tartışılan bir meseleyi ele alacağız: İslam'dan önce kadın gerçekten toplumda tamamen değersiz miydi? İslam bu konuda neyi değiştirdi? Yaygın anlatılar ne kadar doğru, hangi noktalarda daha dikkatli konuşmamız gerekiyor?
                TEXT,
            'key_points' => <<<'TEXT'
                - Cahiliye döneminin tek tip olmadığı
                - Bazı kadınların ekonomik ve toplumsal güce sahip olabildiği
                - Buna rağmen kadınları dezavantajlı hale getiren ciddi uygulamaların bulunduğu
                - Kız çocuklarının öldürülmesi meselesinin kapsamı ve kaynakları
                - Kadının miras ve mülkiyet hakkı
                - Evlilik ve mehir düzenlemeleri
                - Kadının hukuki ve insani şahsiyetinin güçlendirilmesi
                - Normatif İslam öğretisi ile sonraki tarihsel uygulamaların ayrılması
                TEXT,
            'questions_to_push' => <<<'TEXT'
                Hikmet genelleme yaptığında örnek iste.
                "Bu uygulama bütün Araplarda mı vardı?" diye sor.
                "İslam burada gerçekten yeni bir hak mı verdi, yoksa var olan bir uygulamayı mı düzenledi?" diye zorla.
                "Metin başka, tarihsel uygulama başka olabilir mi?" sorusunu özellikle gündeme getir.
                Modern dönemde bu konunun neden bu kadar tartışmalı hale geldiğini sor.
                TEXT,
            'closing_notes' => <<<'TEXT'
                Programın sonunda meseleyi "İslam öncesi tamamen karanlıktı / İslam sonrası bütün sorunlar bitti" ikiliğine indirgemeden özetle. İslam'ın getirdiği normatif düzenlemeler ile Müslüman toplumların tarih boyunca bunları ne ölçüde uyguladığı sorusunu birbirinden ayır.
                TEXT,
            // Episode AI brief ("AI Brifingi")
            'ai_objective' => <<<'TEXT'
                Sunucuyla birlikte İslam öncesi Arap toplumlarında kadının konumunu tarihsel bağlam içinde incelemek ve İslam'ın getirdiği düzenlemelerin kadın açısından hangi hukuki, ekonomik ve insani değişiklikleri amaçladığını açıklamak.
                TEXT,
            'ai_tone_override' => <<<'TEXT'
                Sakin, araştırmacı ve saygılı. Savunmacı veya propaganda dilinden kaçın. Güçlü bir iddia geldiğinde doğrudan reddetmek yerine önce hangi kısmının doğru, hangi kısmının genelleme olduğunu ayır.
                TEXT,
            'must_cover_points' => <<<'TEXT'
                - İslam öncesi Arabistan'da kadınların konumu homojen değildi.
                - Kabile, sınıf, ekonomik durum ve bölge kadınların hayatını etkileyebiliyordu.
                - Kadınların mülkiyet veya ticaret yapabildiğine dair örneklerin bulunması, bütün kadınların eşit haklara sahip olduğu anlamına gelmez.
                - Kur'an'ın miras konusunda kadınlara belirlenmiş paylar tanıması önemli bir hukuki düzenlemedir.
                - Mehir kadının kendisine ait bir hak olarak ele alınmalıdır.
                - Evlilik ve aile ilişkilerinde düzenleme ve sınırlandırmalar getirildi.
                - Kız çocuklarının öldürülmesi Kur'an'da ahlaki olarak açık biçimde mahkûm edilir; ancak uygulamanın tarihsel yaygınlığı konusunda abartılı genellemelerden kaçınılmalıdır.
                - Kadının insan olarak ahlaki sorumluluğu ve manevi değeri erkekle aynı hitap alanında değerlendirilir.
                - Normatif dini hükümler ile daha sonraki Müslüman toplumların kültürel uygulamaları aynı şey değildir.
                TEXT,
            'avoid_points' => <<<'TEXT'
                - "İslam'dan önce kadınların hiçbir hakkı yoktu" şeklinde mutlak genelleme yapma.
                - "Bütün Araplar kız çocuklarını diri diri gömüyordu" deme.
                - Tartışmalı rivayetleri kesin tarihsel veri olarak sunma.
                - Bugünün hukuk kavramlarını hiçbir açıklama yapmadan 7. yüzyıla uygulama.
                - İslam tarihindeki bütün uygulamaları ideal İslam öğretisi gibi savunma.
                - Karşı görüşleri küçümseme.
                TEXT,
            'response_length_guidance' => <<<'TEXT'
                Default response: approximately 80-140 spoken Turkish words. For direct follow-up questions, prefer 40-90 words. Only give longer answers when the presenter explicitly asks for detail.
                TEXT,
        ]);

        if (! $episode->exists) {
            // "Preparing" is a preparable status; the Ready promotion happens
            // later, only through MakeEpisodeReady.
            $episode->status = EpisodeStatus::Preparing;
        }

        $episode->broadcast_at = Carbon::parse('2026-09-13 21:00:00');
        $episode->save();

        return $episode;
    }

    private function seedLineup(Episode $episode, AiPersona $persona): void
    {
        $episode->lineup()->updateOrCreate(
            ['ai_persona_id' => $persona->id],
            [
                'sort_order' => 1,
                'episode_instructions' => <<<'TEXT'
                    Bu bölümde özellikle İslam öncesi kadın tarihi ve İslam'ın getirdiği hukuki/sosyal düzenlemeler üzerinde yoğunlaş. Sunucunun sert veya genelleyici sorularında önce iddiayı parçalarına ayır. Tarihsel olgu, dini norm ve sonraki toplumsal uygulama arasındaki farkı açık tut.
                    TEXT,
            ],
        );
    }

    /**
     * @return int the number of topics seeded
     */
    private function seedTopicsAndQuestions(Episode $episode): int
    {
        foreach ($this->topics() as $index => $topicData) {
            $topic = $episode->topics()->updateOrCreate(
                ['title' => $topicData['title']],
                [
                    'description' => $topicData['description'],
                    'ai_context' => $topicData['ai_context'],
                    'presenter_notes' => $topicData['presenter_notes'],
                    'sort_order' => $index + 1,
                ],
            );

            foreach ($topicData['questions'] as $qIndex => $question) {
                $topic->questions()->updateOrCreate(
                    ['question' => $question['question']],
                    [
                        'ai_context' => $question['ai_context'] ?? null,
                        'sort_order' => $qIndex + 1,
                    ],
                );
            }
        }

        return count($this->topics());
    }

    /**
     * @return list<array{title: string, description: string, ai_context: string, presenter_notes: string, questions: list<array{question: string, ai_context?: string}>}>
     */
    private function topics(): array
    {
        return [
            [
                'title' => 'Cahiliye Döneminde Kadın: Tek Bir Tablo Var mı?',
                'description' => 'İslam öncesi Arap toplumlarında kadınların sosyal ve ekonomik konumunun ne kadar çeşitlilik gösterdiğini değerlendirmek.',
                'ai_context' => '"Cahiliye" kavramının ahlaki/dini bir niteleme olmasının yanında modern anlatıda bazen bütün sosyal hayatı tek tipe indirgeyen bir etiket gibi kullanılabildiğine dikkat et. Bölgesel ve kabilesel farklılıklara alan aç.',
                'presenter_notes' => 'İlk bölümde yaygın "kadın tamamen değersizdi" tezini ortaya at ve Hikmet\'ten bunu sınırlandırmasını iste.',
                'questions' => [
                    ['question' => 'İslam\'dan önce Arap toplumunda kadının gerçekten hiçbir hakkı yok muydu?'],
                    ['question' => 'Hz. Hatice gibi ticaret yapan ve mal sahibi kadınların varlığı, cahiliye döneminde kadınların aslında güçlü olduğunu mu gösteriyor?'],
                    ['question' => 'O dönemde bütün kadınlar için geçerli tek bir sosyal statüden söz edebilir miyiz?'],
                    ['question' => '"Cahiliye dönemi" derken tarihsel olarak neyi kastediyoruz?'],
                ],
            ],
            [
                'title' => 'Kız Çocuklarının Öldürülmesi Meselesi',
                'description' => 'Kur\'an\'ın eleştirdiği kız çocuklarının öldürülmesi uygulamasını tarihsel kapsamı ve dini anlamıyla tartışmak.',
                'ai_context' => 'Kur\'an\'ın uygulamayı ahlaki açıdan güçlü biçimde mahkûm ettiğini açıkla. Fakat uygulamanın bütün kabilelerde ve bütün ailelerde yaygın olduğu şeklindeki genellemeyi tarihsel olarak dikkatle ele al.',
                'presenter_notes' => 'Bu bölümde popüler anlatı ile tarihsel kanıt arasındaki farkı özellikle sorgula.',
                'questions' => [
                    ['question' => 'İslam öncesinde kız çocukları gerçekten diri diri gömülüyor muydu?'],
                    ['question' => 'Bu uygulama bütün Arap toplumunda yaygın mıydı?'],
                    ['question' => 'Kur\'an bu konuya nasıl yaklaştı?'],
                    ['question' => 'Bu anlatı daha sonraki dönemlerde olduğundan daha yaygınmış gibi aktarılmış olabilir mi?'],
                ],
            ],
            [
                'title' => 'Miras, Mal ve Ekonomik Haklar',
                'description' => 'Kadının mülkiyet, miras ve ekonomik şahsiyetinin İslam\'ın hukuki düzenlemeleri içindeki yerini ele almak.',
                'ai_context' => 'Kadının mülkiyet sahibi olabilmesiyle mirastan belirlenmiş hukuki pay almasının aynı mesele olmadığını açık tut.',
                'presenter_notes' => '"Kadın zaten mal sahibi olabiliyorsa İslam neyi değiştirdi?" sorusunu özellikle sor.',
                'questions' => [
                    ['question' => 'İslam\'dan önce kadın mal sahibi olabiliyor muydu?'],
                    ['question' => 'Öyleyse İslam\'ın miras konusunda getirdiği yenilik neydi?'],
                    ['question' => 'Kur\'an\'da kadına miras payı verilmesi dönemin şartlarında ne ifade ediyordu?'],
                    [
                        'question' => 'Erkek ve kadının miras paylarının her durumda aynı olmaması nasıl açıklanmalı?',
                        'ai_context' => 'Bu bölümde bu soruyu tam bir fıkhî tartışmaya dönüştürme; cevabı bağlamsal tut ve miras kurallarının akrabalık yapılandırmasına (kimin kiminle birlikte mirasçı olduğuna) bağlı olduğunu belirt.',
                    ],
                ],
            ],
            [
                'title' => 'Evlilik, Mehir ve Aile İçindeki Konum',
                'description' => 'Evlilik uygulamalarının düzenlenmesi, mehir ve kadının aile içindeki hukuki konumunu konuşmak.',
                'ai_context' => 'Farklı İslam öncesi evlilik biçimlerinin kaynaklarda aktarıldığını, fakat bunların yaygınlığı ve sınıflandırılması konusunda dikkatli olunması gerektiğini belirt.',
                'presenter_notes' => 'Mehirin aileye verilen bir "başlık parası" olup olmadığını Hikmet\'e özellikle sor.',
                'questions' => [
                    ['question' => 'İslam evlilik konusunda kadın açısından hangi düzenlemeleri getirdi?'],
                    ['question' => 'Mehir nedir ve kime aittir?'],
                    ['question' => 'Mehir bir çeşit başlık parası mıdır?'],
                    ['question' => 'Kadının evlilikte rızası meselesini tarihsel bağlamda nasıl değerlendirmeliyiz?'],
                ],
            ],
            [
                'title' => 'Kadının İnsan ve İnanan Olarak Değeri',
                'description' => 'İslam\'ın kadın ve erkeği ahlaki sorumluluk, ibadet ve insanlık değeri açısından nasıl konumlandırdığını tartışmak.',
                'ai_context' => 'Hukuki rollerin her zaman özdeş olmaması ile manevi/ahlaki değer eşitliği tartışmasını birbirinden ayır.',
                'presenter_notes' => '"Değer eşitse hukuk neden her konuda aynı değil?" şeklinde itiraz getir.',
                'questions' => [
                    ['question' => 'İslam\'ın kadına verdiği "değer" derken tam olarak neyi kastediyoruz?'],
                    ['question' => 'Kur\'an kadın ve erkeği manevi sorumluluk açısından nasıl ele alıyor?'],
                    ['question' => 'Kadın ve erkeğin değerinin eşit olması, bütün hukuki hükümlerin aynı olması gerektiği anlamına gelir mi?'],
                    ['question' => 'Dini öğretide verilen değer ile tarih boyunca Müslüman toplumlarda kadının yaşadığı gerçeklik neden her zaman aynı olmadı?'],
                ],
            ],
            [
                'title' => 'Bugünün Tartışmaları: Din mi, Kültür mü?',
                'description' => 'Modern dönemde kadın, İslam ve gelenek tartışmalarında dini normlarla kültürel uygulamaların nasıl birbirine karıştığını değerlendirmek.',
                'ai_context' => 'Ne bütün sorunları "kültür" diyerek dinden ayır ne de tarih boyunca Müslüman toplumlarda görülen her uygulamayı İslam\'ın doğrudan emri gibi sun.',
                'presenter_notes' => 'Programı günümüze bağlayan son tartışma bölümü.',
                'questions' => [
                    ['question' => 'Bugün Müslüman toplumlarda kadına yönelik her uygulamayı İslam\'a bağlamak doğru mu?'],
                    ['question' => 'Bir uygulamanın dini mi kültürel mi olduğunu nasıl ayırabiliriz?'],
                    ['question' => 'İslam\'ın kadın konusunda getirdiği ilkeler tarih boyunca her zaman uygulanabildi mi?'],
                    ['question' => 'Bu tartışmada hem dini savunanların hem dini eleştirenlerin yaptığı en büyük genelleme hataları neler?'],
                ],
            ],
        ];
    }

    private function reportReadiness(EpisodeReadiness $readiness): void
    {
        $rows = [];
        foreach ($readiness->checks() as $check) {
            $rows[] = [$check->key, $check->passed ? 'PASS' : 'FAIL', $check->label];
        }

        $this->command->newLine();
        $this->command->getOutput()->writeln('<info>Readiness assessment (AssessEpisodeReadiness)</info>');
        $this->command->table(['check', 'result', 'label'], $rows);
        $this->command->getOutput()->writeln(
            $readiness->isReady()
                ? '<info>is_ready = true</info>'
                : '<comment>is_ready = false — blocking: '.implode(' · ', $readiness->blockingIssues()).'</comment>',
        );
    }

    private function reportSummary(
        Show $show,
        AiPersona $persona,
        Episode $episode,
        int $topicCount,
        int $questionCount,
        bool $ready,
    ): void {
        $this->command->newLine();
        $this->command->getOutput()->writeln('<info>LocalTestDataSeeder — summary</info>');
        $this->command->table(['field', 'value'], [
            ['Show', $show->uuid.'  —  '.$show->name.'  ('.$show->status->value.')'],
            ['AiPersona', $persona->uuid.'  —  '.$persona->name.'  ('.$persona->status->value.')'],
            ['Episode', $episode->uuid.'  —  '.$episode->title],
            ['Topics', (string) $topicCount],
            ['Questions', (string) $questionCount],
            ['Readiness', $ready ? 'ready (all blocking checks passed)' : 'NOT ready'],
            ['Episode status', $episode->status->value],
        ]);
    }
}
